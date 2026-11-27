<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Enums\ExecutionStatus;
use App\Enums\StepStatus;
use App\Enums\WaitType;
use App\Models\Execution;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class ChildWorkflowTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
    }

    public function test_sync_child_output_becomes_the_calling_steps_output(): void
    {
        $workspace = Workspace::factory()->create();
        $child = $this->publishedWorkflow($this->graph(['start' => 'start', 'result' => ['emit', ['value' => 'from child']]], ['start->result']), $workspace);
        $parent = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'call' => ['call', ['workflow_id' => $child->id]], 'after' => 'emit'],
            ['start->call', 'call->after'],
        ), $workspace);

        $execution = $this->runWorkflow($parent);

        $childRun = Execution::where('workflow_id', $child->id)->sole();
        $call = $this->stepFor($execution, 'call');
        $this->assertSame($call->id, $childRun->parent_step_id);
        $this->assertSame(['from' => 'parent'], $childRun->input);
        $this->assertSame('from child', $call->output['result']['output']['value']);
        $this->assertSame('main', $call->output['result']['port']);
        $this->assertSame(ExecutionStatus::Complete, $execution->status);
    }

    public function test_failed_child_fails_the_calling_step_which_an_error_output_can_catch(): void
    {
        $workspace = Workspace::factory()->create();
        $child = $this->publishedWorkflow($this->graph(['start' => 'start', 'boom' => 'flaky'], ['start->boom']), $workspace);
        $parent = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'call' => ['call', ['workflow_id' => $child->id]], 'handler' => 'emit'],
            ['start->call', 'call:error->handler'],
        ), $workspace);

        $execution = $this->runWorkflow($parent);

        $this->assertSame(StepStatus::Failed, $this->stepFor($execution, 'call')->status);
        $this->assertSame('Failed attempt 1', $this->stepFor($execution, 'handler')->output['error']);
        $this->assertSame(ExecutionStatus::Complete, $execution->status);
    }

    public function test_parent_waits_while_the_child_waits_and_cancelling_it_cancels_the_child(): void
    {
        $workspace = Workspace::factory()->create();
        $child = $this->publishedWorkflow($this->graph(['start' => 'start', 'wait' => 'pause'], ['start->wait']), $workspace);
        $parent = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'call' => ['call', ['workflow_id' => $child->id]]],
            ['start->call'],
        ), $workspace);

        $execution = $this->runWorkflow($parent);
        $childRun = Execution::where('workflow_id', $child->id)->sole();

        $this->assertSame(ExecutionStatus::Waiting, $execution->status);
        $this->assertSame(WaitType::Child, $this->stepFor($execution, 'call')->wait_type);

        app(Engine::class)->cancel($execution);

        $this->assertSame(ExecutionStatus::Cancelled, $childRun->refresh()->status);
    }

    public function test_workflow_can_call_itself(): void
    {
        $workspace = Workspace::factory()->create();
        $workflow = $this->publishedWorkflow($this->graph(['start' => 'start'], []), $workspace);
        $graph = $this->graph(
            ['start' => 'start', 'check' => ['route', ['take' => 'false']], 'call' => ['call', ['workflow_id' => $workflow->id]]],
            ['start->check', 'check:true->call'],
        );
        $workflow->publishedVersion->forceFill(['graph' => $graph])->save();

        $this->assertSame(ExecutionStatus::Complete, $this->runWorkflow($workflow->refresh())->status);
    }
}
