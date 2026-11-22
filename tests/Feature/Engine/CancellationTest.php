<?php

namespace Tests\Feature\Engine;

use App\Engine\Coordinator;
use App\Engine\Engine;
use App\Engine\NodeResult;
use App\Enums\ExecutionStatus;
use App\Enums\StepStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class CancellationTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
    }

    public function test_user_cancels_a_run_and_its_open_steps(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'fork' => 'fork', 'wait' => 'pause', 'ask' => 'ask'],
            ['start->fork', 'fork:a->wait', 'fork:b->ask'],
        ));
        $execution = $this->runWorkflow($workflow);
        $user = User::factory()->create();

        app(Engine::class)->cancel($execution, $user);

        $execution->refresh();
        $this->assertSame(ExecutionStatus::Cancelled, $execution->status);
        $this->assertSame($user->id, $execution->cancelled_by);
        $this->assertSame(StepStatus::Cancelled, $this->stepFor($execution, 'wait')->status);
        $this->assertSame(StepStatus::Cancelled, $this->stepFor($execution, 'ask')->status);
        $this->assertSame(StepStatus::Complete, $this->stepFor($execution, 'start')->status);

        $this->travel(1)->hour();
        $this->sweep();
        $this->assertSame(StepStatus::Cancelled, $this->stepFor($execution, 'wait')->status);
    }

    public function test_result_from_a_worker_whose_step_was_cancelled_is_discarded(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(['start' => 'start', 'wait' => 'pause', 'next' => 'emit'], ['start->wait', 'wait->next']));
        $execution = $this->runWorkflow($workflow);
        $step = $this->stepFor($execution, 'wait');
        $step->forceFill(['status' => StepStatus::Running, 'claim_token' => 'worker-1'])->save();

        app(Engine::class)->cancel($execution);
        app(Coordinator::class)->apply($step, 'worker-1', NodeResult::complete(['late' => true]));

        $this->assertSame(StepStatus::Cancelled, $step->refresh()->status);
        $this->assertNull($step->output);
        $this->assertSame(0, $execution->steps()->where('node_id', 'next')->count());
    }

    #[TestWith(['complete', ExecutionStatus::Complete])]
    #[TestWith(['failed', ExecutionStatus::Failed])]
    #[TestWith(['cancelled', ExecutionStatus::Cancelled])]
    public function test_terminate_ends_the_run_with_its_status_and_cancels_other_branches(string $status, ExecutionStatus $expected): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'fork' => 'fork', 'wait' => 'pause', 'stop' => ['stop', ['status' => $status]]],
            ['start->fork', 'fork:a->wait', 'fork:b->stop'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame($expected, $execution->status);
        $this->assertSame(['stopped' => true], $execution->output);
        $this->assertSame(StepStatus::Cancelled, $this->stepFor($execution, 'wait')->status);
    }

    public function test_terminate_as_failed_cannot_be_caught_by_an_error_output(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'stop' => ['stop', ['status' => 'failed']], 'handler' => 'emit'],
            ['start->stop', 'stop:error->handler'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame(0, $execution->steps()->where('node_id', 'handler')->count());
    }
}
