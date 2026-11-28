<?php

namespace Tests\Feature\Integrations;

use App\Enums\StepStatus;
use App\Models\Credential;
use App\Models\ExecutionStep;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class GoogleSheetsTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private const VALUES = 'sheets.googleapis.com/v4/spreadsheets/abc/values/*';

    private const BATCH_UPDATE = 'sheets.googleapis.com/v4/spreadsheets/abc/values:batchUpdate';

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->workspace = Workspace::factory()->create();
        Credential::factory()->for($this->workspace)->oauth('google', ['access_token' => 'ya29.token'])->create(['id' => $this->settingId('google'), 'description' => 'google']);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $input
     */
    private function runAction(string $type, array $config, array $input = []): ExecutionStep
    {
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'act' => [$type, ['credential' => $this->settingId('google'), 'spreadsheet' => 'abc', 'sheet' => 'Orders', ...$config], ['max_retries' => 1, 'backoff_seconds' => 0]]],
            ['start->act'],
        ), $this->workspace), ['values' => $input]);

        return $this->stepFor($execution, 'act');
    }

    /**
     * @param  list<list<mixed>>  $values
     * @return array<string, mixed>
     */
    private function sheet(array $values, string $range = "'Orders'!A1:Z1000"): array
    {
        return ['range' => $range, 'majorDimension' => 'ROWS', 'values' => $values];
    }

    public function test_adds_a_row_to_a_google_sheet(): void
    {
        Http::fake(['sheets.googleapis.com/*' => Http::response(['updates' => ['updatedRange' => "'Orders'!A5:C5", 'updatedRows' => 1]])]);

        $step = $this->runAction('google.append_sheet_row', [
            'spreadsheet' => 'https://docs.google.com/spreadsheets/d/1AbC_d-9/edit#gid=0',
            'values' => [['value' => '{{ trigger.values.id }}'], ['value' => '{{ trigger.values.total }}'], ['value' => 'paid']],
        ], ['id' => 991, 'total' => 12.5]);

        $this->assertSame(['range' => "'Orders'!A5:C5", 'rows' => 1], $step->output);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/1AbC_d-9/values/%27Orders%27:append?')
            && str_contains($request->url(), 'valueInputOption=USER_ENTERED')
            && $request->data() === ['majorDimension' => 'ROWS', 'values' => [[991, 12.5, 'paid']]]
            && $request->header('Authorization') === ['Bearer ya29.token']);
    }

    public function test_reads_rows_as_objects_by_header_with_their_row_numbers_leaving_out_empty_rows(): void
    {
        Http::fake([self::VALUES => Http::response($this->sheet([
            ['Name', 'Email', '', 'Name'],
            ['Ada', 'ada@example.com', 3, 'x'],
            [],
            ['Grace', 'grace@example.com'],
        ]))]);

        $step = $this->runAction('google.read_sheet_rows', []);

        $this->assertSame([
            'rows' => [
                ['Name' => 'Ada', 'Email' => 'ada@example.com', 'C' => 3, 'Name (D)' => 'x', '_row' => 2],
                ['Name' => 'Grace', 'Email' => 'grace@example.com', 'C' => '', 'Name (D)' => '', '_row' => 4],
            ],
            'count' => 2,
            'headers' => ['Name', 'Email', 'C', 'Name (D)'],
        ], $step->output);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/abc/values/%27Orders%27?')
            && $request['valueRenderOption'] === 'UNFORMATTED_VALUE'
            && $request['dateTimeRenderOption'] === 'FORMATTED_STRING'
            && $request->header('Authorization') === ['Bearer ya29.token']);
    }

    public function test_reads_only_the_rows_where_a_column_holds_a_value(): void
    {
        Http::fake([self::VALUES => Http::response($this->sheet([
            ['Email', 'Status'],
            ['ada@example.com', 'sent'],
            ['grace@example.com', ''],
            ['alan@example.com'],
        ]))]);

        $pending = $this->runAction('google.read_sheet_rows', ['match_column' => 'status', 'match_value' => '']);
        $sent = $this->runAction('google.read_sheet_rows', ['match_column' => 'B', 'match_value' => '{{ trigger.values.status }}'], ['status' => 'sent']);

        $this->assertSame([3, 4], array_column($pending->output['rows'], '_row'), 'a header matches ignoring case, and an empty value finds empty cells');
        $this->assertSame([2], array_column($sent->output['rows'], '_row'), 'a column letter works too');
    }

    public function test_reads_cells_without_headers_keyed_by_column_letter_from_where_the_range_starts(): void
    {
        Http::fake([self::VALUES => Http::response($this->sheet([['Ada', 36], ['Grace', 85]], "'Orders'!B5:C6"))]);

        $step = $this->runAction('google.read_sheet_rows', ['cells' => 'B5:C', 'headers' => false]);

        $this->assertSame([['B' => 'Ada', 'C' => 36, '_row' => 5], ['B' => 'Grace', 'C' => 85, '_row' => 6]], $step->output['rows']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/values/%27Orders%27%21B5%3AC?'));
    }

    public function test_a_column_the_sheet_lacks_or_a_refusal_fails_with_the_reason(): void
    {
        Http::fake([self::VALUES => Http::sequence()
            ->push($this->sheet([['Email']]))
            ->push(['error' => ['code' => 403, 'message' => 'The caller does not have permission']], 403)]);

        $missing = $this->runAction('google.read_sheet_rows', ['match_column' => 'Status', 'match_value' => 'x']);
        $refused = $this->runAction('google.read_sheet_rows', []);

        $this->assertSame('The sheet has no column "Status". Use a header from its first row, or a column letter such as C.', $missing->error['message']);
        $this->assertSame(StepStatus::Failed, $refused->status);
        $this->assertSame('Google answered 403: The caller does not have permission', $refused->error['message']);
        Http::assertSentCount(2);
    }

    public function test_updates_a_row_by_its_number_naming_columns_by_header_or_letter(): void
    {
        Http::fake([
            self::VALUES => Http::response($this->sheet([['Email', 'Status', 'Notes']], "'Orders'!A1:Z1")),
            self::BATCH_UPDATE => Http::response(['totalUpdatedCells' => 2]),
        ]);

        $step = $this->runAction('google.update_sheet_row', [
            'find_by' => 'number',
            'row' => '{{ trigger.values.row }}',
            'values' => ['status' => 'sent', 'E' => '{{ trigger.values.at }}'],
        ], ['row' => 7, 'at' => '2026-11-27']);

        $this->assertSame(['rows' => 1, 'row_numbers' => [7]], $step->output);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/values/%27Orders%27%211%3A1?'));
        Http::assertSent(fn (Request $request) => $request->url() === 'https://sheets.googleapis.com/v4/spreadsheets/abc/values:batchUpdate'
            && $request->data() === ['valueInputOption' => 'USER_ENTERED', 'data' => [
                ['range' => "'Orders'!B7", 'values' => [['sent']]],
                ['range' => "'Orders'!E7", 'values' => [['2026-11-27']]],
            ]]);
    }

    public function test_updates_every_row_where_a_column_holds_a_value_and_changes_nothing_when_none_does(): void
    {
        Http::fake([
            self::VALUES => Http::response($this->sheet([
                ['Order', 'Status'],
                [1001, 'new'],
                [1002, 'new'],
                [1001, 'new'],
            ])),
            self::BATCH_UPDATE => Http::response(['totalUpdatedCells' => 2]),
        ]);

        $matched = $this->runAction('google.update_sheet_row', ['find_by' => 'value', 'match_column' => 'Order', 'match_value' => '1001', 'values' => ['Status' => 'shipped']]);
        $unmatched = $this->runAction('google.update_sheet_row', ['find_by' => 'value', 'match_column' => 'Order', 'match_value' => '9999', 'values' => ['Status' => 'shipped']]);

        $this->assertSame(['rows' => 2, 'row_numbers' => [2, 4]], $matched->output);
        $this->assertSame(['rows' => 0, 'row_numbers' => []], $unmatched->output);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), ':batchUpdate') && array_column($request['data'], 'range') === ["'Orders'!B2", "'Orders'!B4"]);
        $this->assertCount(1, Http::recorded(fn (Request $request) => str_ends_with($request->url(), ':batchUpdate')));
    }

    public function test_an_update_without_a_usable_row_or_column_fails_before_writing(): void
    {
        Http::fake([self::VALUES => Http::response($this->sheet([['Email', 'Status']], "'Orders'!A1:Z1"))]);

        $badRow = $this->runAction('google.update_sheet_row', ['find_by' => 'number', 'row' => 'abc', 'values' => ['Status' => 'x']]);
        $badColumn = $this->runAction('google.update_sheet_row', ['find_by' => 'number', 'row' => 2, 'values' => ['Shipped on' => 'x']]);

        $this->assertSame('The row number must be a whole number of 1 or more, not "abc".', $badRow->error['message']);
        $this->assertSame('The sheet has no column "Shipped on". Use a header from its first row, or a column letter such as C.', $badColumn->error['message']);
        $this->assertSame(1, $badColumn->attempt, 'not retried');
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), ':batchUpdate'));
    }
}
