<?php

namespace App\Engine\Nodes\Google;

use App\Engine\Definitions\Field;
use App\Engine\Definitions\Output;
use App\Engine\Node;
use App\Engine\NodeContext;
use App\Engine\NodeResult;
use App\Enums\NodeKind;
use App\Services\OAuthConnections;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Adds a row after the last one in a Google Sheets sheet, as the workspace's
 * connected Google account. Values are entered as if typed, so numbers and
 * dates are recognised.
 */
class AppendSheetRow extends Node
{
    public static function type(): string
    {
        return 'google.append_sheet_row';
    }

    public function label(): string
    {
        return __('nodes.google_sheets.add_row');
    }

    public function description(): string
    {
        return __('nodes.google_sheets.add_row_description');
    }

    public function kind(): NodeKind
    {
        return NodeKind::Action;
    }

    public function category(): string
    {
        return __('nodes.google_sheets.google');
    }

    public function icon(): string
    {
        return 'table-cells';
    }

    public function inputs(): array
    {
        return [
            new Field('credential', __('nodes.google_sheets.google_account'), Field::CREDENTIAL, required: true, credentialTypes: ['google'], description: __('nodes.google_sheets.connect_one_under_authentication')),
            new Field('spreadsheet', __('nodes.google_sheets.spreadsheet'), Field::TEXT, required: true, templated: true, description: __('nodes.google_sheets.spreadsheet_help')),
            new Field('sheet', __('nodes.google_sheets.sheet'), Field::TEXT, required: true, default: 'Sheet1', templated: true),
            new Field('values', __('nodes.google_sheets.values'), Field::LIST, required: true, templated: true, default: [['value' => '']], description: __('nodes.google_sheets.values_help'), fields: [
                new Field('value', __('nodes.google_sheets.value'), Field::TEXT),
            ]),
        ];
    }

    public function outputs(array $config = []): array
    {
        return [
            new Output('range', __('nodes.google_sheets.updated_range'), 'string'),
            new Output('rows', __('nodes.google_sheets.rows_added'), 'number'),
        ];
    }

    public function execute(NodeContext $context): NodeResult
    {
        $credential = $context->credential($context->input('credential'));

        if (! $credential || $credential->type !== 'google') {
            return NodeResult::fail(__('nodes.google_sheets.connection_not_found'), retryable: false);
        }

        $spreadsheet = $this->spreadsheetId((string) $context->input('spreadsheet'));
        $range = rawurlencode("'".str_replace("'", "''", trim((string) $context->input('sheet')))."'");
        $values = array_map(
            fn (mixed $row) => is_array($row) ? ($row['value'] ?? null) : $row,
            array_values((array) $context->input('values', [])),
        );

        try {
            $response = Http::withToken(app(OAuthConnections::class)->accessToken($credential))
                ->timeout(30)
                ->withQueryParameters(['valueInputOption' => 'USER_ENTERED', 'insertDataOption' => 'INSERT_ROWS'])
                ->post("https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheet}/values/{$range}:append", [
                    'majorDimension' => 'ROWS',
                    'values' => [array_map(fn (mixed $value) => is_scalar($value) || $value === null ? $value : json_encode($value), $values)],
                ]);
        } catch (ConnectionException $e) {
            return NodeResult::fail(__('nodes.google_sheets.could_not_reach_google', ['message' => $e->getMessage()]));
        } catch (RuntimeException $e) {
            return NodeResult::fail($e->getMessage(), retryable: false);
        }

        if (! $response->successful()) {
            return NodeResult::fail(
                __('nodes.google_sheets.google_answered', ['status' => $response->status(), 'message' => $response->json('error.message') ?? Str::limit(trim($response->body()), 300)]),
                retryable: $response->serverError() || $response->status() === 429,
            );
        }

        return NodeResult::complete([
            'range' => $response->json('updates.updatedRange'),
            'rows' => (int) ($response->json('updates.updatedRows') ?? 1),
        ]);
    }

    /**
     * The spreadsheet's id, from the id itself or its URL.
     */
    protected function spreadsheetId(string $value): string
    {
        $value = trim($value);

        return rawurlencode(preg_match('#/spreadsheets/d/([A-Za-z0-9_-]+)#', $value, $match) ? $match[1] : $value);
    }
}
