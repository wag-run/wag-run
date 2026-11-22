<?php

namespace Tests\Feature\Engine;

use App\Enums\ConcurrencyPolicy;
use App\Enums\ExecutionStatus;
use App\Models\Workflow;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class ConcurrencyTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
    }

    private function waitingWorkflow(ConcurrencyPolicy $policy): Workflow
    {
        return Workflow::factory()
            ->for(Workspace::factory())
            ->concurrency($policy)
            ->published($this->graph(['start' => 'start', 'wait' => 'pause'], ['start->wait']))
            ->create();
    }

    public function test_skip_policy_drops_a_new_run_while_one_is_in_progress(): void
    {
        $workflow = $this->waitingWorkflow(ConcurrencyPolicy::SkipIfRunning);
        $first = $this->runWorkflow($workflow);

        $second = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Waiting, $first->refresh()->status);
        $this->assertSame(ExecutionStatus::Skipped, $second->status);
        $this->assertSame(0, $second->steps()->count());
        $this->assertNotNull($second->finished_at);
    }

    public function test_cancel_previous_policy_replaces_the_run_in_progress(): void
    {
        $workflow = $this->waitingWorkflow(ConcurrencyPolicy::CancelPrevious);
        $first = $this->runWorkflow($workflow);

        $second = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Cancelled, $first->refresh()->status);
        $this->assertSame(0, $first->openSteps()->count());
        $this->assertSame(ExecutionStatus::Waiting, $second->status);
    }

    public function test_allow_policy_runs_concurrently(): void
    {
        $workflow = $this->waitingWorkflow(ConcurrencyPolicy::Allow);

        $this->runWorkflow($workflow);
        $this->runWorkflow($workflow);

        $this->assertSame(2, $workflow->executions()->where('status', ExecutionStatus::Waiting)->count());
    }

    public function test_skip_policy_allows_a_new_run_once_the_previous_finished(): void
    {
        $workflow = $this->waitingWorkflow(ConcurrencyPolicy::SkipIfRunning);
        $this->runWorkflow($workflow);
        $this->travel(10)->minutes();
        $this->sweep();

        $this->assertSame(ExecutionStatus::Waiting, $this->runWorkflow($workflow)->status);
    }
}
