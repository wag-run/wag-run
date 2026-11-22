<?php

namespace Tests\Feature\Engine;

use App\Enums\ExecutionStatus;
use App\Enums\StepStatus;
use App\Services\Workflows;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class ExecutionFlowTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerFixtureNodes();
    }

    public function test_linear_run_records_one_complete_step_per_node_in_order(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'first' => ['emit', ['value' => 'one']], 'second' => ['emit', ['value' => 'two']]],
            ['start->first', 'first->second'],
        ));

        $execution = $this->runWorkflow($workflow, ['order' => 42]);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(['start', 'first', 'second'], $this->nodesRun($execution));
        $this->assertSame([StepStatus::Complete], $execution->steps()->get()->pluck('status')->unique()->values()->all());
        $this->assertSame(['order' => 42], $this->stepFor($execution, 'start')->output);
        $this->assertSame(['start', 'first'], $this->stepFor($execution, 'second')->output['saw']);
        $this->assertSame($this->stepFor($execution, 'first')->id, $this->stepFor($execution, 'second')->previous_step_id);
        $this->assertSame(['second'], array_keys($execution->output));
        $this->assertNotNull($execution->finished_at);
    }

    public function test_paths_not_taken_have_no_step_rows(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'check' => ['route', ['take' => 'false']], 'yes' => 'emit', 'no' => 'emit'],
            ['start->check', 'check:true->yes', 'check:false->no'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(['start', 'check', 'no'], $this->nodesRun($execution));
        $this->assertSame(['false'], $this->stepFor($execution, 'check')->output_ports);
    }

    public function test_runs_stay_pinned_to_the_version_they_started_on(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'wait' => 'pause', 'after' => ['emit', ['value' => 'v1']]],
            ['start->wait', 'wait->after'],
        ));
        $execution = $this->runWorkflow($workflow);
        $firstVersion = $workflow->published_version_id;

        $workflow->forceFill(['draft_graph' => $this->graph(
            ['start' => 'start', 'wait' => 'pause', 'after' => ['emit', ['value' => 'v2']]],
            ['start->wait', 'wait->after'],
        )])->save();
        app(Workflows::class)->publish($workflow);

        $this->travel(10)->minutes();
        $this->sweep();

        $execution->refresh();
        $this->assertSame($firstVersion, $execution->workflow_version_id);
        $this->assertSame('v1', $this->stepFor($execution, 'after')->output['value']);
        $this->assertSame('v2', $this->stepFor($this->runWorkflow($workflow->refresh()), 'start')->execution->version->graph['nodes'][2]['config']['value']);
    }

    public function test_branch_runs_every_path_and_merge_waits_for_all_of_them(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'fork' => 'fork', 'left' => 'emit', 'right' => 'pause', 'merge' => 'join', 'end' => 'emit'],
            ['start->fork', 'fork:a->left', 'fork:b->right', 'left->merge', 'right->merge', 'merge->end'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Waiting, $execution->status);
        $this->assertSame(StepStatus::Waiting, $this->stepFor($execution, 'merge')->status);
        $this->assertSame(0, $execution->steps()->where('node_id', 'end')->count());

        $this->travel(10)->minutes();
        $this->sweep();

        $execution->refresh();
        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(['left', 'right'], $this->stepFor($execution, 'merge')->output['arrived']);
        $this->assertSame(1, $execution->steps()->where('node_id', 'end')->count());
    }

    public function test_merge_proceeds_when_a_path_into_it_was_never_taken(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'check' => ['route', ['take' => 'true']], 'yes' => 'emit', 'no' => 'emit', 'merge' => 'join'],
            ['start->check', 'check:true->yes', 'check:false->no', 'yes->merge', 'no->merge'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(['yes'], $this->stepFor($execution, 'merge')->output['arrived']);
    }

    public function test_for_loop_runs_its_body_once_per_item_then_continues(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'each' => ['each', ['items' => ['a', 'b', 'c']]], 'body' => 'emit', 'after' => 'emit'],
            ['start->each', 'each:item->body', 'each:done->after'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $bodies = $execution->steps()->where('node_id', 'body')->orderBy('id')->get();
        $this->assertSame(['a', 'b', 'c'], $bodies->map(fn ($step) => $step->output['item'])->all());
        $loopId = $this->stepFor($execution, 'each')->id;
        $this->assertSame(["L{$loopId}.0", "L{$loopId}.1", "L{$loopId}.2"], $bodies->pluck('scope_key')->all());
        $this->assertSame(['a', 'b', 'c'], array_map(fn ($result) => $result['body']['item'], $this->stepFor($execution, 'each')->output['results']));
        $this->assertSame(1, $execution->steps()->where('node_id', 'after')->count());
        $this->assertNotContains('body', $this->stepFor($execution, 'after')->output['saw']);
    }

    public function test_nested_loops_run_every_combination(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'outer' => ['each', ['items' => [[1, 2], [3]]]], 'inner' => ['each', ['items_from_item' => true]], 'body' => 'emit'],
            ['start->outer', 'outer:item->inner', 'inner:item->body'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame([1, 2, 3], $execution->steps()->where('node_id', 'body')->orderBy('id')->get()->map(fn ($step) => $step->output['item'])->all());
    }

    public function test_loop_without_items_continues_straight_to_done(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'each' => ['each', ['items' => []]], 'body' => 'emit', 'after' => 'emit'],
            ['start->each', 'each:item->body', 'each:done->after'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(['start', 'each', 'after'], $this->nodesRun($execution));
        $this->assertSame(['results' => []], $this->stepFor($execution, 'each')->output);
    }

    public function test_waiting_inside_a_loop_holds_the_next_iteration(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'each' => ['each', ['items' => ['x', 'y']]], 'wait' => 'pause'],
            ['start->each', 'each:item->wait'],
        ));

        $execution = $this->runWorkflow($workflow);
        $this->assertSame(ExecutionStatus::Waiting, $execution->status);
        $this->assertSame(1, $execution->steps()->where('node_id', 'wait')->count());

        $this->travel(6)->minutes();
        $this->sweep();
        $this->assertSame(2, $execution->steps()->where('node_id', 'wait')->count());

        $this->travel(6)->minutes();
        $this->sweep();
        $this->assertSame(ExecutionStatus::Complete, $execution->refresh()->status);
    }
}
