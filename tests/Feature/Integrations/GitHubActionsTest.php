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

class GitHubActionsTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->workspace = Workspace::factory()->create();
        Credential::factory()->for($this->workspace)->oauth('github')->create(['id' => $this->settingId('github'), 'description' => 'github']);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $input
     */
    private function runAction(string $type, array $config, array $input = []): ExecutionStep
    {
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'act' => [$type, ['credential' => $this->settingId('github'), 'repository' => '{{ trigger.repo }}', ...$config], ['max_retries' => 1, 'backoff_seconds' => 0]]],
            ['start->act'],
        ), $this->workspace), ['repo' => 'https://github.com/acme/app', ...$input]);

        return $this->stepFor($execution, 'act');
    }

    public function test_creates_an_issue_with_labels_and_assignees(): void
    {
        Http::fake(['api.github.com/repos/acme/app/issues' => Http::response(['id' => 1, 'number' => 42, 'html_url' => 'https://github.com/acme/app/issues/42'], 201)]);

        $step = $this->runAction('github.create_issue', ['title' => 'Deploy of {{ trigger.tag }} failed', 'labels' => 'bug, ops', 'assignees' => ''], ['tag' => 'v1']);

        $this->assertSame(['number' => 42, 'url' => 'https://github.com/acme/app/issues/42', 'id' => 1], $step->output);
        Http::assertSent(fn (Request $request) => $request->data() === ['title' => 'Deploy of v1 failed', 'labels' => ['bug', 'ops']]
            && $request->header('Authorization') === ['Bearer gho_access']
            && $request->header('X-GitHub-Api-Version') === ['2022-11-28']);
    }

    public function test_comments_creates_releases_and_runs_actions_workflows(): void
    {
        Http::fake([
            'api.github.com/repos/acme/app/issues/7/comments' => Http::response(['id' => 3, 'html_url' => 'https://github.com/acme/app/issues/7#c3'], 201),
            'api.github.com/repos/acme/app/releases' => Http::response(['id' => 9, 'tag_name' => 'v2', 'html_url' => 'https://github.com/acme/app/releases/v2'], 201),
            'api.github.com/repos/acme/app/actions/workflows/deploy.yml/dispatches' => Http::response(null, 204),
        ]);

        $this->assertSame(3, $this->runAction('github.add_comment', ['number' => '#7', 'body' => 'Shipped'])->output['id']);
        $this->assertSame('v2', $this->runAction('github.create_release', ['tag' => 'v2', 'generate_notes' => true])->output['tag']);
        $this->assertSame(['workflow' => 'deploy.yml', 'ref' => 'main'], $this->runAction('github.run_actions_workflow', ['workflow' => 'deploy.yml', 'ref' => 'main', 'inputs' => ['env' => 'production']])->output);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/releases') && $request->data() === ['tag_name' => 'v2', 'generate_release_notes' => true, 'draft' => false, 'prerelease' => false]);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/dispatches') && $request->data() === ['ref' => 'main', 'inputs' => ['env' => 'production']]);
    }

    public function test_github_errors_fail_the_step_and_only_server_errors_are_retried(): void
    {
        Http::fake(['api.github.com/*' => Http::sequence()
            ->push(['message' => 'Validation Failed'], 422)
            ->push(['message' => 'Server Error'], 502)
            ->push(['id' => 1, 'number' => 2, 'html_url' => 'u'], 201)]);

        $invalid = $this->runAction('github.create_issue', ['title' => 'x']);
        $this->assertSame(StepStatus::Failed, $invalid->status);
        $this->assertSame('GitHub answered 422: Validation Failed', $invalid->error['message']);
        $this->assertSame(1, $invalid->execution->steps()->where('node_id', 'act')->count(), 'not retried');

        $retried = $this->runAction('github.create_issue', ['title' => 'x']);
        $this->assertSame(StepStatus::Complete, $this->stepFor($retried->execution, 'act', 2)->status);
    }

    public function test_a_missing_connection_or_bad_repository_fails_without_calling_github(): void
    {
        Http::fake();

        $this->assertSame('The GitHub account chosen on this node no longer exists. Choose or connect another in the node\'s settings.', $this->runAction('github.create_issue', ['credential' => $this->settingId('nope'), 'title' => 'x'])->error['message']);
        $this->assertStringContainsString('isn\'t a repository', $this->runAction('github.create_issue', ['title' => 'x'], ['repo' => 'acme'])->error['message']);
        Http::assertNothingSent();
    }

    public function test_adds_a_row_to_a_google_sheet(): void
    {
        Credential::factory()->for($this->workspace)->oauth('google', ['access_token' => 'ya29.token'])->create(['id' => $this->settingId('google'), 'description' => 'google']);
        Http::fake(['sheets.googleapis.com/*' => Http::response(['updates' => ['updatedRange' => "'Orders'!A5:C5", 'updatedRows' => 1]])]);

        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'add' => ['google.append_sheet_row', [
                'credential' => $this->settingId('google'),
                'spreadsheet' => 'https://docs.google.com/spreadsheets/d/1AbC_d-9/edit#gid=0',
                'sheet' => 'Orders',
                'values' => [['value' => '{{ trigger.id }}'], ['value' => '{{ trigger.total }}'], ['value' => 'paid']],
            ]]],
            ['start->add'],
        ), $this->workspace), ['id' => 991, 'total' => 12.5]);

        $this->assertSame(['range' => "'Orders'!A5:C5", 'rows' => 1], $this->stepFor($execution, 'add')->output);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://sheets.googleapis.com/v4/spreadsheets/1AbC_d-9/values/%27Orders%27:append?')
            && str_contains($request->url(), 'valueInputOption=USER_ENTERED')
            && $request->data() === ['majorDimension' => 'ROWS', 'values' => [[991, 12.5, 'paid']]]
            && $request->header('Authorization') === ['Bearer ya29.token']);
    }
}
