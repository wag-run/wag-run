<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\Redactor;
use App\Enums\ConcurrencyPolicy;
use App\Enums\ExecutionStatus;
use App\Enums\StepStatus;
use App\Enums\WorkspaceRole;
use App\Http\Resources\ExecutionResource;
use App\Mcp\Servers\WorkflowServer;
use App\Mcp\Tools\GetRun;
use App\Mcp\Tools\ListRuns;
use App\Models\Credential;
use App\Models\Execution;
use App\Models\ExecutionMaskedValue;
use App\Models\Organization;
use App\Models\Secret;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkflowApi;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\Concerns\RunsWorkflows;
use Tests\Fixtures\Nodes\Capture;
use Tests\TestCase;

class MaskingTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private const JWT = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiJhZG1pbiJ9.c2lnbmF0dXJlLXZhbHVl';

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
        Capture::$seen = [];
    }

    /**
     * A graph whose nodes carry masking settings, by node id.
     *
     * @param  array<string, mixed>  $graph
     * @param  array<string, array{always?: list<string>, never?: list<string>}>  $masking
     * @return array<string, mixed>
     */
    private function withMasking(array $graph, array $masking): array
    {
        foreach ($graph['nodes'] as $index => $node) {
            if (isset($masking[$node['id']])) {
                $graph['nodes'][$index]['masking'] = $masking[$node['id']];
            }
        }

        return $graph;
    }

    private function fakeHosts(): void
    {
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.215.14'];
            }
        });
        Http::preventStrayRequests();
    }

    /**
     * Everything the run's rows hold, as one string.
     */
    private function rows(Execution $execution): string
    {
        $execution->refresh();

        return json_encode([$execution->steps()->get()->toArray(), $execution->variables()->get()->toArray(), $execution->withoutRelations()->toArray()]);
    }

    public function test_secret_and_credential_values_are_masked_before_step_rows_are_written(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['value' => 'sk_live_supersecret']);
        Credential::factory()->for($workspace)->create(['config' => ['header' => 'Authorization', 'prefix' => 'Bearer', 'token' => 'ghp_tokenvalue']]);
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'leak' => ['emit', ['value' => 'key=sk_live_supersecret token=ghp_tokenvalue']], 'denied' => ['flaky', ['message' => 'Rejected ghp_tokenvalue']]],
            ['start->leak', 'leak->denied'],
        ), $workspace);

        $execution = $this->runWorkflow($workflow, ['password' => 'sk_live_supersecret']);

        $leak = $this->stepFor($execution, 'leak');
        $masked = 'key='.Redactor::MASK.' token='.Redactor::MASK;
        $this->assertSame($masked, $leak->input['value']);
        $this->assertSame($masked, $leak->output['value']);
        $this->assertSame(['password' => Redactor::MASK], $execution->input);
        $this->assertSame('Rejected '.Redactor::MASK, $this->stepFor($execution, 'denied')->error['message']);
        $this->assertSame('Rejected '.Redactor::MASK, $execution->error['message']);

        $rows = json_encode($execution->steps()->get()->toArray()).json_encode($execution->withoutRelations()->toArray());
        $this->assertStringNotContainsString('sk_live_supersecret', $rows);
        $this->assertStringNotContainsString('ghp_tokenvalue', $rows);
    }

    public function test_short_values_and_other_workspaces_secrets_are_not_masked(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['value' => '42']);
        Secret::factory()->for(Workspace::factory())->create(['value' => 'other-workspace-secret']);

        $redactor = Redactor::forWorkspace($workspace);

        $this->assertSame(['n' => 'answer 42', 'x' => 'other-workspace-secret'], $redactor->mask(['n' => 'answer 42', 'x' => 'other-workspace-secret']));
    }

    public function test_a_secret_among_the_items_a_loop_goes_through_is_masked_in_the_state_the_loop_saves(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['value' => 'sk_live_supersecret']);
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'each' => ['core.for_loop', ['items' => '["key=sk_live_supersecret", "plain"]']]],
            ['start->each'],
        ), $workspace);

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(['key='.Redactor::MASK, 'plain'], $this->stepFor($execution, 'each')->state['items']);
    }

    public function test_a_secret_a_failed_step_returns_in_its_output_is_masked(): void
    {
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.215.14'];
            }
        });
        Http::preventStrayRequests();
        Http::fake(['https://api.example.com/charge' => Http::response('Invalid key sk_live_supersecret', 401)]);
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['value' => 'sk_live_supersecret']);
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'call' => ['core.http_request', ['method' => 'POST', 'url' => 'https://api.example.com/charge']]],
            ['start->call'],
        ), $workspace);

        $execution = $this->runWorkflow($workflow);

        $call = $this->stepFor($execution, 'call');
        $this->assertSame(StepStatus::Failed, $call->status);
        $this->assertSame('Invalid key '.Redactor::MASK, $call->output['body']);
    }

    public function test_a_secret_in_what_a_terminate_node_ends_the_run_with_is_masked_on_the_step_and_the_run(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['value' => 'sk_live_supersecret']);
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'stop' => ['core.terminate', ['status' => 'failed', 'output' => 'Refused key sk_live_supersecret']]],
            ['start->stop'],
        ), $workspace);

        $execution = $this->runWorkflow($workflow);

        $this->assertSame('Refused key '.Redactor::MASK, $this->stepFor($execution, 'stop')->output);
        $this->assertSame('Refused key '.Redactor::MASK, $execution->output);
    }

    public function test_a_secret_that_contains_another_secret_is_masked_whole(): void
    {
        $workspace = Workspace::factory()->create();
        Secret::factory()->for($workspace)->create(['key' => 'prefix', 'value' => 'tok_live']);
        Secret::factory()->for($workspace)->create(['key' => 'token', 'value' => 'tok_live_9f8e7d']);

        $redactor = Redactor::forWorkspace($workspace);

        $this->assertSame('key '.Redactor::MASK.' and '.Redactor::MASK, $redactor->mask('key tok_live_9f8e7d and tok_live'));
    }

    public function test_a_token_a_login_returns_is_masked_but_the_next_request_still_sends_it(): void
    {
        $this->fakeHosts();
        Http::fake([
            'https://npm.example.com/api/tokens' => Http::response(['token' => self::JWT, 'expires' => '2026-12-01']),
            'https://npm.example.com/api/backup' => Http::response(['ok' => true]),
        ]);
        $workflow = $this->publishedWorkflow($this->graph(
            [
                'start' => 'start',
                'npm_login' => ['core.http_request', ['method' => 'POST', 'url' => 'https://npm.example.com/api/tokens', 'body_type' => 'json', 'body' => '{"identity": "ada@example.com", "secret": "hunter2!"}']],
                'backup' => ['core.http_request', ['url' => 'https://npm.example.com/api/backup', 'headers' => ['Authorization' => 'Bearer {{ nodes.npm_login.output.body.token }}']]],
            ],
            ['start->npm_login', 'npm_login->backup'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(['token' => Redactor::MASK, 'expires' => '2026-12-01'], $this->stepFor($execution, 'npm_login')->output['body']);
        $this->assertSame(Redactor::MASK, $this->stepFor($execution, 'backup')->input['headers']['Authorization']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://npm.example.com/api/backup' && $request->header('Authorization') === ['Bearer '.self::JWT]);
        $this->assertStringNotContainsString(self::JWT, $this->rows($execution));
        $this->assertStringNotContainsString('hunter2!', $this->rows($execution));
    }

    public function test_a_token_is_masked_wherever_it_shows_up_again_in_the_run(): void
    {
        $this->fakeHosts();
        Http::fake(['https://api.example.com/*' => Http::response(['echo' => 'opaque-session-4242'])]);
        $workflow = $this->publishedWorkflow($this->graph(
            [
                'start' => 'start',
                'login' => ['capture', ['value' => '{{ trigger.session_id }}']],
                'note' => ['core.compose', ['template' => 'Signed in with {{ nodes.login.output.token }}']],
                'call' => ['core.http_request', ['url' => 'https://api.example.com/items?sid={{ nodes.login.output.token }}']],
                'deny' => ['capture', ['value' => '{{ nodes.login.output.token }}', 'fail' => true]],
            ],
            ['start->login', 'login->note', 'note->call', 'call->deny'],
        ));

        $execution = $this->runWorkflow($workflow, ['session_id' => 'opaque-session-4242']);

        $this->assertSame('Signed in with '.Redactor::MASK, $this->stepFor($execution, 'note')->output['value']);
        $this->assertSame('https://api.example.com/items?sid='.Redactor::MASK, $this->stepFor($execution, 'call')->input['url']);
        $this->assertSame(['echo' => Redactor::MASK], $this->stepFor($execution, 'call')->output['body']);
        $this->assertSame('Rejected '.Redactor::MASK, $this->stepFor($execution, 'deny')->error['message']);
        $this->assertSame('Rejected '.Redactor::MASK, $execution->error['message']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.example.com/items?sid=opaque-session-4242');
        $this->assertStringNotContainsString('opaque-session-4242', $this->rows($execution));
    }

    public function test_a_for_loops_items_and_results_keep_their_real_values_for_later_steps_and_show_masked(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            [
                'start' => 'start',
                'each' => ['core.for_loop', ['items' => '{{ trigger.accounts }}']],
                'grab' => ['capture', ['value' => '{{ item.token }}']],
                'after' => ['capture', ['value' => '{{ nodes.each.output.results[1].grab.output.token }}']],
            ],
            ['start->each', 'each:item->grab', 'each:done->after'],
        ));

        $execution = $this->runWorkflow($workflow, ['accounts' => [['token' => 'tok-aaaa-1111'], ['token' => 'tok-bbbb-2222']]]);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(['tok-aaaa-1111', 'tok-bbbb-2222'], Capture::$seen['grab']);
        $this->assertSame(['tok-bbbb-2222'], Capture::$seen['after']);
        $this->assertSame([['token' => Redactor::MASK], ['token' => Redactor::MASK]], $this->stepFor($execution, 'each')->state['items']);
        $this->assertSame([['token' => Redactor::MASK], ['token' => Redactor::MASK]], $execution->steps()->where('node_id', 'grab')->orderBy('id')->pluck('output')->all());
        $this->assertStringNotContainsString('tok-bbbb-2222', $this->rows($execution));
    }

    public function test_a_while_loops_passes_keep_their_real_values_for_later_steps_and_show_masked(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            [
                'start' => 'start',
                'poll' => ['core.while_loop', ['condition' => '{{ loop.index < 1 }}', 'check_after' => true]],
                'grab' => ['capture', ['value' => '{{ trigger.token }}-{{ loop.index }}']],
                'after' => ['capture', ['value' => '{{ nodes.poll.output.last.grab.output.token }}']],
            ],
            ['start->poll', 'poll:pass->grab', 'poll:done->after'],
        ));

        $execution = $this->runWorkflow($workflow, ['token' => 'while-secret']);

        $this->assertSame(['while-secret-0', 'while-secret-1'], Capture::$seen['grab']);
        $this->assertSame(['while-secret-1'], Capture::$seen['after']);
        $this->assertSame(Redactor::MASK, $execution->output['after']['output']['token']);
        $this->assertStringNotContainsString('while-secret', $this->rows($execution));
    }

    public function test_a_token_in_a_webhook_body_can_be_read_from_the_trigger_and_is_masked_in_the_runs_input(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'use' => ['capture', ['value' => '{{ trigger.body.token }}|{{ nodes.start.output.body.token }}']]],
            ['start->use'],
        ));

        $execution = $this->runWorkflow($workflow, ['body' => ['token' => 'whk-secret-123', 'event' => 'push']]);

        $this->assertSame(['whk-secret-123|whk-secret-123'], Capture::$seen['use']);
        $this->assertSame(['body' => ['token' => Redactor::MASK, 'event' => 'push']], $execution->input);
        $this->assertStringNotContainsString('whk-secret-123', $this->rows($execution));
    }

    public function test_a_token_saved_to_a_variable_can_be_read_back_during_the_run(): void
    {
        $workflow = $this->publishedWorkflow([
            ...$this->graph(
                [
                    'start' => 'start',
                    'save' => ['core.variable_update', ['variable' => 'session', 'value' => '{{ trigger.body.token }}']],
                    'use' => ['capture', ['value' => '{{ vars.session }}']],
                ],
                ['start->save', 'save->use'],
            ),
            'variables' => [['name' => 'session', 'type' => 'string', 'value' => ''], ['name' => 'api_token', 'type' => 'string', 'value' => '{{ trigger.body.token }}']],
        ]);

        $execution = $this->runWorkflow($workflow, ['body' => ['token' => 'var-secret-777']]);

        $this->assertSame(['var-secret-777'], Capture::$seen['use']);
        $this->assertEquals(['session' => Redactor::MASK, 'api_token' => Redactor::MASK], $execution->variables()->pluck('value', 'name')->map(fn (string $json) => json_decode($json))->all());
        $this->assertStringNotContainsString('var-secret-777', $this->rows($execution));
    }

    public function test_a_run_that_waits_keeps_its_real_values_and_resumes_with_them(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            [
                'start' => 'start',
                'hold' => ['capture', ['value' => '{{ trigger.token }}', 'wait_minutes' => 5]],
                'after' => ['capture', ['value' => '{{ nodes.hold.output.token }}']],
            ],
            ['start->hold', 'hold->after'],
        ));

        $execution = $this->runWorkflow($workflow, ['token' => 'paused-secret-1']);

        $this->assertSame(ExecutionStatus::Waiting, $execution->status);
        $this->assertSame(['kept' => ['token' => Redactor::MASK]], $this->stepFor($execution, 'hold')->state);
        $this->assertTrue(ExecutionMaskedValue::query()->where('execution_id', $execution->id)->exists());

        $this->travel(6)->minutes();
        $this->sweep();

        $this->assertSame(ExecutionStatus::Complete, $execution->refresh()->status);
        $this->assertSame(['paused-secret-1'], Capture::$seen['hold']);
        $this->assertSame(['paused-secret-1'], Capture::$seen['after']);
        $this->assertFalse(ExecutionMaskedValue::query()->where('execution_id', $execution->id)->exists());
    }

    public function test_the_real_values_are_dropped_whenever_a_run_ends(): void
    {
        $graph = fn (string $then) => $this->graph(['start' => 'start', 'then' => $then], ['start->then']);
        $kept = fn (Execution $execution) => ExecutionMaskedValue::query()->where('execution_id', $execution->id)->count();

        $complete = $this->runWorkflow($this->publishedWorkflow($graph('emit')), ['token' => 'ends-1']);
        $failed = $this->runWorkflow($this->publishedWorkflow($graph('flaky')), ['token' => 'ends-2']);
        $waiting = $this->publishedWorkflow($graph('pause'));
        $waiting->forceFill(['concurrency' => ConcurrencyPolicy::SkipIfRunning])->save();
        $cancelled = $this->runWorkflow($waiting, ['token' => 'ends-3']);
        $this->assertSame(2, $kept($cancelled), 'the trigger data and the start step\'s output');
        $skipped = $this->runWorkflow($waiting, ['token' => 'ends-4']);
        $this->app->make(Engine::class)->cancel($cancelled);

        $this->assertSame(
            [ExecutionStatus::Complete, ExecutionStatus::Failed, ExecutionStatus::Cancelled, ExecutionStatus::Skipped],
            [$complete->status, $failed->status, $cancelled->refresh()->status, $skipped->status],
        );
        $this->assertSame(0, ExecutionMaskedValue::query()->count());
        $this->assertSame(['token' => Redactor::MASK], $skipped->input);
    }

    public function test_the_api_and_mcp_never_return_a_real_value(): void
    {
        $workspace = Workspace::factory()->create();
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'login' => ['capture', ['value' => '{{ trigger.token }}']], 'wait' => 'pause'],
            ['start->login', 'login->wait'],
        ), $workspace);
        $run = $this->runWorkflow($workflow, ['token' => 'api-secret-9999']);
        $this->assertTrue(ExecutionMaskedValue::query()->where('execution_id', $run->id)->exists(), 'the run is still in flight');
        $user = User::factory()->memberOf($workspace, WorkspaceRole::Editor)->create();

        Passport::actingAs($user, [WorkflowApi::SCOPE_RUNS_READ]);
        $api = $this->getJson(route('api.workspaces.runs.show', [$workspace, $run]))->assertOk()->getContent()
            .$this->getJson(route('api.workspaces.runs.index', $workspace))->assertOk()->getContent();
        $resource = json_encode((new ExecutionResource($run->load(['steps', 'variables'])))->resolve());

        $server = WorkflowServer::actingAs($user);
        $server->tool(GetRun::class, ['workspace' => $workspace->slug, 'run' => $run->id])->assertOk()->assertSee('"node_id":"login"')->assertDontSee('api-secret-9999');
        $server->tool(ListRuns::class, ['workspace' => $workspace->slug])->assertOk()->assertSee($run->id)->assertDontSee('api-secret-9999');

        $this->assertStringContainsString('"token":"'.Redactor::MASK.'"', $api);
        $this->assertStringNotContainsString('api-secret-9999', $api.$resource);
    }

    public function test_the_organizations_names_and_a_nodes_own_paths_decide_what_is_masked(): void
    {
        Organization::factory()->create(['sensitive_keys' => ['X-Tenant-Key']]);
        $workflow = $this->publishedWorkflow($this->withMasking($this->graph(['start' => 'start']), [
            'start' => ['always' => ['data[*].license'], 'never' => ['next_page_token', 'session_token']],
        ]));

        $execution = $this->runWorkflow($workflow, [
            'x_tenant_key' => 'tenant-1234',
            'data' => [['license' => 'L-1234-5678', 'id' => 7]],
            'next_page_token' => 'page-2-cursor',
            'session_token' => self::JWT,
        ]);

        $this->assertSame([
            'x_tenant_key' => Redactor::MASK,
            'data' => [['license' => Redactor::MASK, 'id' => 7]],
            'next_page_token' => 'page-2-cursor',
            'session_token' => Redactor::MASK,
        ], $execution->input);
    }
}
