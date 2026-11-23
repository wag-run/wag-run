<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Enums\ExecutionStatus;
use App\Models\Execution;
use App\Models\Organization;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\ManualRuns;
use App\Services\Workflows;
use App\Support\HostResolver;
use App\Support\RunChain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class RunLoopProtectionTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    private function webhookWorkflow(?int $maxRunsPerMinute = null): Workflow
    {
        $workflow = Workflow::factory()->for(Workspace::factory())->create([
            'max_runs_per_minute' => $maxRunsPerMinute,
            'draft_graph' => $this->graph(['hook' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']]]),
        ]);
        app(Workflows::class)->publish($workflow);

        return $workflow->refresh();
    }

    public function test_runs_past_the_workflows_limit_are_cancelled_until_the_minute_is_over(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger']));
        $workflow->update(['max_runs_per_minute' => 2]);

        $runs = [$this->runWorkflow($workflow), $this->runWorkflow($workflow), $this->runWorkflow($workflow)];

        $this->assertSame(ExecutionStatus::Complete, $runs[1]->status);
        $this->assertSame(ExecutionStatus::Cancelled, $runs[2]->status);
        $this->assertSame(Engine::RUN_LIMIT_REACHED, $runs[2]->error['code']);
        $this->assertStringContainsString('already started 2 runs in the last minute', $runs[2]->error['message']);
        $this->assertSame(0, $runs[2]->steps()->count());

        $this->travel(61)->seconds();

        $this->assertSame(ExecutionStatus::Complete, $this->runWorkflow($workflow)->status);
    }

    public function test_the_organization_default_applies_and_test_runs_do_not_count(): void
    {
        Organization::factory()->create(['max_runs_per_minute' => 1]);
        $user = User::factory()->create();
        $workflow = $this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger']));
        $workflow->update(['draft_graph' => $workflow->publishedVersion->graph]);

        $test = app(ManualRuns::class)->startTest($workflow, $user);
        $first = $this->runWorkflow($workflow);
        $second = $this->runWorkflow($workflow);
        $anotherTest = app(ManualRuns::class)->startTest($workflow, $user);

        $this->assertSame(ExecutionStatus::Complete, $test->refresh()->status);
        $this->assertSame(ExecutionStatus::Complete, $first->status);
        $this->assertSame(ExecutionStatus::Cancelled, $second->status);
        $this->assertSame(ExecutionStatus::Complete, $anotherTest->refresh()->status);
    }

    public function test_a_webhook_past_the_limit_answers_too_many_requests(): void
    {
        $workflow = $this->webhookWorkflow(maxRunsPerMinute: 1);
        $url = route('webhooks.receive', $workflow->triggers()->sole()->key);

        $this->postJson($url)->assertAccepted();
        $this->postJson($url)->assertTooManyRequests()->assertHeader('Retry-After', '60');

        $this->assertSame([ExecutionStatus::Complete, ExecutionStatus::Cancelled], Execution::query()->orderBy('id')->get()->pluck('status')->all());
    }

    public function test_a_webhook_called_by_one_of_this_installs_runs_continues_its_chain(): void
    {
        Organization::factory()->create(['max_chain_depth' => 3]);
        $workflow = $this->webhookWorkflow();
        $url = route('webhooks.receive', $workflow->triggers()->sole()->key);
        $parent = $this->runWorkflow($this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger'])));
        $parent->update(['chain_depth' => 3]);

        $this->postJson($url, [], [RunChain::HEADER => RunChain::headerFor($parent)])->assertAccepted();
        $this->postJson($url, [], [RunChain::HEADER => $parent->id.'.forged'])->assertAccepted();

        [$chained, $forged] = $workflow->executions()->orderBy('id')->get()->all();
        $this->assertSame(4, $chained->chain_depth);
        $this->assertSame(ExecutionStatus::Cancelled, $chained->status, 'past the maximum chain depth');
        $this->assertArrayNotHasKey('x-wag-run', $chained->input['headers']);
        $this->assertSame(1, $forged->chain_depth, 'a header this install did not sign starts a new chain');
    }

    public function test_http_calls_to_this_install_name_the_run_making_them(): void
    {
        config(['app.url' => 'https://wag.example.com']);
        $this->app->instance(HostResolver::class, new class extends HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.215.14'];
            }
        });
        Http::fake();

        $own = $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.http_request', ['method' => 'POST', 'url' => 'https://wag.example.com/hooks/abc']]],
            ['start->call'],
        )));
        $this->runWorkflow($this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.http_request', ['method' => 'POST', 'url' => 'https://api.example.com/hooks/abc']]],
            ['start->call'],
        )));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'wag.example.com') && $request->header(RunChain::HEADER) === [RunChain::headerFor($own)]);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.example.com') && ! $request->hasHeader(RunChain::HEADER));
    }
}
