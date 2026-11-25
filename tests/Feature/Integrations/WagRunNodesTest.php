<?php

namespace Tests\Feature\Integrations;

use App\Enums\ExecutionStatus;
use App\Enums\StepStatus;
use App\Enums\WorkspaceRole;
use App\Models\Credential;
use App\Models\ExecutionStep;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\ObjectStorage;
use App\Support\HostResolver;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class WagRunNodesTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['app.url' => 'https://wag.test']);
        $this->workspace = Workspace::factory()->create();
        $this->token('api', '');

        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return match ($host) {
                    'wag.example.com' => ['93.184.216.34'],
                    'wag.corp.internal' => ['10.20.0.9'],
                    'backups.s3.us-east-1.amazonaws.com' => ['52.216.1.1'],
                    default => parent::resolve($host),
                };
            }
        });
    }

    private function token(string $name, string $url, ?Workspace $workspace = null): void
    {
        Credential::factory()->for($workspace ?? $this->workspace)->create(['id' => $this->settingId($name), 'description' => $name, 'type' => 'wag_run', 'config' => ['url' => $url, 'token' => "token-{$name}"]]);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $input
     */
    private function runAction(string $type, array $config = [], array $input = [], ?Workspace $workspace = null): ExecutionStep
    {
        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'act' => [$type, ['credential' => $this->settingId('api'), ...$config], ['max_retries' => 1, 'backoff_seconds' => 0]]],
            ['start->act'],
        ), $workspace ?? $this->workspace), ['values' => $input]);

        return $this->stepFor($execution, 'act');
    }

    public function test_lists_the_workspaces_of_this_install_with_the_token(): void
    {
        $workspaces = [['slug' => 'ops', 'name' => 'Ops', 'url' => 'https://wag.test/w/ops'], ['slug' => 'it', 'name' => 'IT', 'url' => 'https://wag.test/w/it']];
        Http::fake(['wag.test/api/v1/workspaces' => Http::response(['data' => $workspaces])]);

        $step = $this->runAction('wag_run.list_workspaces');

        $this->assertSame(['workspaces' => $workspaces, 'count' => 2], $step->output);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://wag.test/api/v1/workspaces'
            && $request->header('Authorization') === ['Bearer token-api']
            && $request->header('Accept') === ['application/json']);
    }

    public function test_lists_a_workspaces_workflows_with_definitions_only_when_asked(): void
    {
        $workflows = [['id' => 'a1', 'name' => 'Refunds', 'definition' => ['format' => 'wag.run/workflow']]];
        Http::fake(['wag.test/api/v1/workspaces/ops/workflows*' => Http::response(['data' => $workflows])]);

        $plain = $this->runAction('wag_run.list_workflows', ['workspace' => '{{ trigger.values.workspace }}'], ['workspace' => 'ops']);
        $full = $this->runAction('wag_run.list_workflows', ['workspace' => ' ops ', 'include_definitions' => true]);

        $this->assertSame(['workflows' => $workflows, 'count' => 1, 'workspace' => 'ops'], $full->output);
        $this->assertSame(
            ['https://wag.test/api/v1/workspaces/ops/workflows', 'https://wag.test/api/v1/workspaces/ops/workflows?include=definition'],
            Http::recorded()->map(fn (array $pair) => $pair[0]->url())->all(),
        );
        $this->assertSame('ops', $plain->output['workspace']);
    }

    public function test_gets_one_workflow_keeping_the_workspace_and_id_inside_their_path_segments(): void
    {
        Http::fake(['wag.test/api/v1/workspaces/*' => Http::response(['data' => ['id' => 'a1', 'name' => 'Refunds', 'definition' => ['graph' => []]]])]);

        $step = $this->runAction('wag_run.get_workflow', ['workspace' => 'ops', 'workflow' => '{{ trigger.values.id }}'], ['id' => 'a1/../../runs']);

        $this->assertSame(['workflow' => ['id' => 'a1', 'name' => 'Refunds', 'definition' => ['graph' => []]], 'workspace' => 'ops'], $step->output);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://wag.test/api/v1/workspaces/ops/workflows/a1%2F..%2F..%2Fruns');
    }

    public function test_reaches_another_install_at_the_address_in_the_setting(): void
    {
        $this->token('remote', 'https://wag.example.com/');
        Http::fake(['wag.example.com/api/v1/workspaces' => Http::response(['data' => []])]);

        $step = $this->runAction('wag_run.list_workspaces', ['credential' => $this->settingId('remote')]);

        $this->assertSame(['workspaces' => [], 'count' => 0], $step->output);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://wag.example.com/api/v1/workspaces'
            && $request->header('Authorization') === ['Bearer token-remote']);
    }

    public function test_another_install_on_an_internal_address_needs_the_workspaces_allowlist(): void
    {
        $this->token('internal', 'https://wag.corp.internal');
        $allowed = Workspace::factory()->create(['internal_allowlist' => ['*.corp.internal']]);
        $this->token('allowed', 'https://wag.corp.internal', $allowed);
        Http::fake(['wag.corp.internal/api/v1/workspaces' => Http::response(['data' => []])]);

        $refused = $this->runAction('wag_run.list_workspaces', ['credential' => $this->settingId('internal')]);

        $this->assertSame(StepStatus::Failed, $refused->status);
        $this->assertStringContainsString('10.20.0.9', $refused->error['message']);
        Http::assertNothingSent();

        $this->assertSame(StepStatus::Complete, $this->runAction('wag_run.list_workspaces', ['credential' => $this->settingId('allowed')], workspace: $allowed)->status);
    }

    public function test_follows_this_installs_redirect_to_https_but_not_to_another_host(): void
    {
        config(['app.url' => 'http://wag.test']);
        Http::fake([
            'http://wag.test/api/v1/workspaces' => Http::response('', 301, ['Location' => 'https://wag.test/api/v1/workspaces']),
            'https://wag.test/api/v1/workspaces' => Http::response(['data' => [['slug' => 'ops']]]),
            'http://wag.test/api/v1/workspaces/ops/workflows' => Http::response('', 302, ['Location' => 'https://evil.example.com/steal']),
        ]);

        $this->assertSame(['workspaces' => [['slug' => 'ops']], 'count' => 1], $this->runAction('wag_run.list_workspaces')->output);
        $this->assertSame(
            [['http://wag.test/api/v1/workspaces', ['Bearer token-api']], ['https://wag.test/api/v1/workspaces', ['Bearer token-api']]],
            Http::recorded()->map(fn (array $pair) => [$pair[0]->url(), $pair[0]->header('Authorization')])->all(),
        );

        $this->assertSame(
            'wag.run answered 302, sending the request on to https://evil.example.com/steal. Put that address in the API token setting, or set APP_URL to it for this install.',
            $this->runAction('wag_run.list_workflows', ['workspace' => 'ops'])->error['message'],
        );
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'evil.example.com'));
    }

    public function test_another_install_that_redirects_fails_with_where_it_points(): void
    {
        $this->token('remote', 'http://wag.example.com');
        Http::fake(['wag.example.com/api/v1/workspaces' => Http::response('', 301, ['Location' => 'https://wag.example.com/api/v1/workspaces'])]);

        $step = $this->runAction('wag_run.list_workspaces', ['credential' => $this->settingId('remote')]);

        $this->assertSame(StepStatus::Failed, $step->status);
        $this->assertStringContainsString('sending the request on to https://wag.example.com/api/v1/workspaces', $step->error['message']);
        Http::assertSentCount(1);
    }

    public function test_refusals_fail_the_step_with_the_apis_message_and_only_server_errors_are_retried(): void
    {
        Http::fake([
            'wag.test/api/v1/workspaces' => Http::response(['message' => 'The token does not have the "workflows:read" scope.'], 403),
            'wag.test/api/v1/workspaces/ops/workflows' => Http::sequence()->push('Bad gateway', 502)->push(['data' => []]),
        ]);

        $refused = $this->runAction('wag_run.list_workspaces');
        $this->assertSame(StepStatus::Failed, $refused->status);
        $this->assertSame('wag.run answered 403: The token does not have the "workflows:read" scope.', $refused->error['message']);
        $this->assertSame(1, $refused->execution->steps()->where('node_id', 'act')->count());

        $execution = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'act' => ['wag_run.list_workflows', ['credential' => $this->settingId('api'), 'workspace' => 'ops'], ['max_retries' => 1, 'backoff_seconds' => 0]]],
            ['start->act'],
        ), $this->workspace));
        $this->sweep();

        $this->assertSame(StepStatus::Complete, $this->stepFor($execution, 'act', 2)->status);
    }

    public function test_fails_without_a_request_when_the_setting_or_the_workspace_is_missing(): void
    {
        Credential::factory()->for($this->workspace)->create(['id' => $this->settingId('other'), 'description' => 'other']);

        $this->assertSame('The API token setting was not found in this workspace.', $this->runAction('wag_run.list_workspaces', ['credential' => $this->settingId('other')])->error['message']);
        $this->assertSame('The workspace is empty.', $this->runAction('wag_run.list_workflows', ['workspace' => '{{ trigger.values.workspace }}'], ['workspace' => ''])->error['message']);
        $this->assertSame('wag.run did not answer with JSON. Check the address in the API token setting.', (function () {
            Http::fake(['wag.test/api/v1/workspaces' => Http::response('<html>Sign in</html>')]);

            return $this->runAction('wag_run.list_workspaces')->error['message'];
        })());
    }

    public function test_a_weekly_backup_saves_every_workflow_of_every_workspace_to_a_dated_folder(): void
    {
        $user = User::factory()->memberOf($this->workspace, WorkspaceRole::Editor)->create();
        $other = Workspace::factory()->create();
        $user->workspaces()->attach($other, ['role' => WorkspaceRole::Viewer->value]);
        $kept = [
            Workflow::factory()->for($this->workspace)->create(['name' => 'Refund approval']),
            Workflow::factory()->for($other)->create(['name' => 'Order updates']),
        ];
        Credential::factory()->for($this->workspace)->s3()->create(['id' => $this->settingId('storage'), 'description' => 'storage']);
        $backup = $this->publishedWorkflow($this->graph([
            'start' => ['core.schedule_trigger', ['mode' => 'cron', 'cron' => '0 0 * * 5', 'timezone' => 'UTC']],
            'workspaces' => ['wag_run.list_workspaces', ['credential' => $this->settingId('api')]],
            'each_workspace' => ['core.for_loop', ['items' => '{{ nodes.workspaces.output.workspaces }}']],
            'workflows' => ['wag_run.list_workflows', ['credential' => $this->settingId('api'), 'workspace' => '{{ item.slug }}', 'include_definitions' => true]],
            'each_workflow' => ['core.for_loop', ['items' => '{{ nodes.workflows.output.workflows }}']],
            'save' => ['core.s3_upload', [
                'credential' => $this->settingId('storage'),
                'bucket' => 'backups',
                'key' => "{{ date(execution.started_at, 'Y-m-d') }} wag-run workflows/{{ nodes.workflows.output.workspace }}/{{ slug(item.name) }}-{{ item.id }}.workflow.json",
                'content' => '{{ item.definition }}',
                'content_type' => 'application/json',
            ]],
        ], ['start->workspaces', 'workspaces->each_workspace', 'each_workspace:item->workflows', 'workflows->each_workflow', 'each_workflow:item->save']), $this->workspace);

        // The nodes' requests are answered by this install's own API, as the token's person.
        Passport::actingAs($user, ['workflows:read']);
        Http::fake(fn (Request $request) => Http::response($this->getJson($request->url())->assertOk()->json()));
        $saved = [];
        $storage = $this->app->make(ObjectStorage::class);
        $storage->handler = new MockHandler;
        $storage->handler->append(...array_fill(0, 3, function (CommandInterface $command) use (&$saved) {
            $saved[$command['Key']] = json_decode((string) $command['Body'], true);

            return new Result([]);
        }));
        $this->app->instance(ObjectStorage::class, $storage);
        $this->travelTo('2026-11-27 00:00:05');

        $execution = $this->runWorkflow($backup);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $expected = collect([$backup, ...$kept])->mapWithKeys(fn (Workflow $workflow) => [
            "2026-11-27 wag-run workflows/{$workflow->workspace->slug}/".Str::slug($workflow->name)."-{$workflow->id}.workflow.json" => $workflow->name,
        ]);
        $this->assertEqualsCanonicalizing($expected->keys()->all(), array_keys($saved));
        foreach ($expected as $key => $name) {
            $this->assertSame(['wag.run/workflow', $name], [$saved[$key]['format'], $saved[$key]['name']]);
        }
    }
}
