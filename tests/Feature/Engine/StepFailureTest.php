<?php

namespace Tests\Feature\Engine;

use App\Engine\StepRunner;
use App\Engine\Sweeper;
use App\Enums\ExecutionStatus;
use App\Enums\StepStatus;
use App\Jobs\RunStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class StepFailureTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
    }

    public function test_each_retry_is_a_new_row_that_waits_out_its_backoff(): void
    {
        $this->freezeSecond();
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'call' => ['flaky', ['fail_times' => 2], ['max_retries' => 3, 'backoff_seconds' => 10, 'multiplier' => 2]]],
            ['start->call'],
        ));

        $execution = $this->runWorkflow($workflow);

        $first = $this->stepFor($execution, 'call', 1);
        $second = $this->stepFor($execution, 'call', 2);
        $this->assertSame(StepStatus::Failed, $first->status);
        $this->assertSame('Failed attempt 1', $first->error['message']);
        $this->assertSame(StepStatus::Pending, $second->status);
        $this->assertSame($first->id, $second->retry_of_id);
        $this->assertTrue($second->available_at->eq(now()->addSeconds(10)));
        $this->assertSame(ExecutionStatus::Running, $execution->status);

        (new RunStep($second->id))->handle(app(StepRunner::class));
        $this->assertSame(StepStatus::Pending, $second->refresh()->status, 'a retry does not run before its backoff');

        // The sync test queue ignores job delays, so the sweep's fallback pickup runs each retry.
        $this->travel(Sweeper::REQUEUE_AFTER + 10)->seconds();
        $this->sweep();
        $third = $this->stepFor($execution, 'call', 3);
        $this->assertSame(StepStatus::Failed, $second->refresh()->status);
        $this->assertTrue($third->available_at->eq(now()->addSeconds(20)), 'backoff doubles');

        $this->travel(Sweeper::REQUEUE_AFTER + 20)->seconds();
        $this->sweep();

        $this->assertSame(StepStatus::Complete, $third->refresh()->status);
        $this->assertSame(['attempt' => 3], $third->output);
        $this->assertSame(ExecutionStatus::Complete, $execution->refresh()->status);
    }

    public function test_exhausted_retries_follow_the_error_output_and_the_run_completes(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'call' => ['flaky', [], ['max_retries' => 1, 'backoff_seconds' => 0]], 'handler' => 'emit', 'next' => 'emit'],
            ['start->call', 'call->next', 'call:error->handler'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(['start', 'call', 'call', 'handler'], $this->nodesRun($execution));
        $handler = $this->stepFor($execution, 'handler');
        $this->assertSame('error', $handler->entry_port);
        $this->assertSame('Failed attempt 2', $handler->output['error']);
    }

    public function test_uncaught_failure_fails_the_run_and_cancels_other_open_steps(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'fork' => 'fork', 'slow' => 'pause', 'call' => 'flaky'],
            ['start->fork', 'fork:a->slow', 'fork:b->call'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame(['message' => 'Failed attempt 1', 'node_id' => 'call', 'step_id' => $this->stepFor($execution, 'call')->id], $execution->error);
        $this->assertSame(StepStatus::Cancelled, $this->stepFor($execution, 'slow')->status);
        $this->assertSame(0, $execution->openSteps()->count());
    }

    public function test_an_exception_thrown_by_a_node_is_a_retryable_failure(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'call' => ['flaky', ['fail_times' => 1, 'throw' => true], ['max_retries' => 1, 'backoff_seconds' => 0]]],
            ['start->call'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame('Exploded on attempt 1', $this->stepFor($execution, 'call', 1)->error['message']);
        $this->assertSame(StepStatus::Complete, $this->stepFor($execution, 'call', 2)->status);
        $this->assertSame(ExecutionStatus::Complete, $execution->status);
    }

    public function test_non_retryable_failures_skip_remaining_retries(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'call' => ['flaky', ['permanent' => true], ['max_retries' => 5]]],
            ['start->call'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(1, $execution->steps()->where('node_id', 'call')->count());
        $this->assertSame(ExecutionStatus::Failed, $execution->status);
    }

    public function test_failure_inside_a_branch_can_be_caught_while_other_branches_continue(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'fork' => 'fork', 'ok' => 'emit', 'call' => 'flaky', 'handler' => 'emit', 'merge' => 'join'],
            ['start->fork', 'fork:a->ok', 'fork:b->call', 'call:error->handler', 'ok->merge', 'handler->merge'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(['handler', 'ok'], $this->stepFor($execution, 'merge')->output['arrived']);
    }
}
