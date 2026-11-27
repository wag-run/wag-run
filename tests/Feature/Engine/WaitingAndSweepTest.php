<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\StepRunner;
use App\Enums\ExecutionStatus;
use App\Enums\StepStatus;
use App\Enums\WaitType;
use App\Jobs\RunStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class WaitingAndSweepTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
    }

    public function test_wait_parks_the_run_without_a_worker_until_the_sweep_resumes_it(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'wait' => ['pause', ['minutes' => 30]], 'after' => 'emit'],
            ['start->wait', 'wait->after'],
        ));

        $execution = $this->runWorkflow($workflow);
        $wait = $this->stepFor($execution, 'wait');

        $this->assertSame(ExecutionStatus::Waiting, $execution->status);
        $this->assertSame(WaitType::Timer, $wait->wait_type);
        $this->assertNull($wait->claim_token);

        $this->travel(29)->minutes();
        $this->sweep();
        $this->assertSame(StepStatus::Waiting, $wait->refresh()->status);

        $this->travel(2)->minutes();
        $this->sweep();

        $this->assertSame(['waited' => true], $wait->refresh()->output);
        $this->assertSame(ExecutionStatus::Complete, $execution->refresh()->status);
    }

    public function test_external_response_resumes_a_waiting_step_with_its_payload(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'ask' => 'ask', 'after' => 'emit'],
            ['start->ask', 'ask->after'],
        ));
        $execution = $this->runWorkflow($workflow);

        app(Engine::class)->respond($this->stepFor($execution, 'ask'), ['answer' => 'approved']);

        $this->assertSame(['answer' => 'approved'], $this->stepFor($execution, 'ask')->output);
        $this->assertSame(ExecutionStatus::Complete, $execution->refresh()->status);
    }

    public function test_response_timeout_is_a_step_failure_that_the_error_output_can_catch(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'ask' => ['ask', ['timeout_hours' => 48]], 'escalate' => 'emit'],
            ['start->ask', 'ask:error->escalate'],
        ));
        $execution = $this->runWorkflow($workflow);

        $this->travel(49)->hours();
        $this->sweep();

        $this->assertSame(StepStatus::Failed, $this->stepFor($execution, 'ask')->status);
        $this->assertSame('No response before the timeout.', $this->stepFor($execution, 'escalate')->output['error']);
        $this->assertSame(ExecutionStatus::Complete, $execution->refresh()->status);
    }

    public function test_responding_to_a_step_that_is_not_waiting_is_rejected(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(['start' => 'start']));
        $execution = $this->runWorkflow($workflow);

        $this->expectException(\InvalidArgumentException::class);

        app(Engine::class)->respond($this->stepFor($execution, 'start'), []);
    }

    public function test_sweep_queues_ready_steps_that_no_worker_picked_up(): void
    {
        Queue::fake();
        $workflow = $this->publishedWorkflow($this->graph(['start' => 'start']));
        $execution = app(Engine::class)->start($workflow);
        $step = $this->stepFor($execution, 'start');
        Queue::assertPushed(RunStep::class, 1);

        $step->forceFill(['queued_at' => null])->save();
        $this->sweep();

        Queue::assertPushed(RunStep::class, 2);
        Queue::assertPushedOn('workflows', RunStep::class);
    }

    public function test_step_whose_worker_died_is_failed_and_retried(): void
    {
        Queue::fake();
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'call' => ['emit', [], ['max_retries' => 1, 'backoff_seconds' => 0]]],
            ['start->call'],
        ));
        $execution = app(Engine::class)->start($workflow);
        $execution->steps()->create([
            'node_id' => 'call', 'node_type' => 'test.emit', 'status' => StepStatus::Running,
            'claim_token' => 'dead-worker', 'started_at' => now(), 'heartbeat_at' => now(),
        ]);

        $this->travel(5)->minutes();
        $this->sweep();
        $this->assertSame(StepStatus::Running, $this->stepFor($execution, 'call')->status, 'still inside the node timeout');

        $this->travel(2)->minutes();
        $this->sweep();

        $this->assertSame(StepStatus::Failed, $this->stepFor($execution, 'call')->status);
        $this->assertSame('The worker running this step stopped responding.', $this->stepFor($execution, 'call')->error['message']);
        $this->assertSame(StepStatus::Pending, $this->stepFor($execution, 'call', 2)->status);
    }

    public function test_a_claimed_step_cannot_be_claimed_again(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(['start' => 'start', 'call' => 'emit'], ['start->call']));
        $execution = $this->runWorkflow($workflow);
        $step = $this->stepFor($execution, 'call');
        $finishedAt = $step->finished_at;

        $this->travel(5)->seconds();
        (new RunStep($step->id))->handle(app(StepRunner::class));

        $this->assertSame(1, $execution->steps()->where('node_id', 'call')->count());
        $this->assertTrue($step->refresh()->finished_at->eq($finishedAt));
    }

    public function test_sweep_command_reports_what_it_did(): void
    {
        Queue::fake();
        $execution = app(Engine::class)->start($this->publishedWorkflow($this->graph(['start' => 'start'])));
        $this->stepFor($execution, 'start')->forceFill(['queued_at' => null])->save();

        $this->artisan('workflows:sweep')
            ->expectsOutputToContain('Requeued 1, worker lost 0, resumed 0, resettled 0.')
            ->assertSuccessful();
    }
}
