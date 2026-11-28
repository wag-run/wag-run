<?php

namespace Tests\Feature\Engine;

use App\Engine\Engine;
use App\Engine\LoopResults;
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
        $this->assertSame(['second' => ['output' => $this->stepFor($execution, 'second')->output, 'port' => 'main']], $execution->output);
        $this->assertNotNull($execution->finished_at);
    }

    public function test_steps_can_read_the_id_type_and_settings_of_the_trigger_that_started_the_run(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            [
                'manual' => 'core.manual_trigger',
                'form' => ['core.form_trigger', ['title' => 'New hire', 'fields' => [['name' => 'who', 'label' => 'Who', 'type' => 'text']]]],
                'say' => ['core.compose', ['template' => '{{ execution.trigger.id }} {{ execution.trigger.type }} {{ execution.trigger.config.title }}']],
            ],
            ['manual->say', 'form->say'],
        ));

        $fromForm = $this->app->make(Engine::class)->start($workflow, ['values' => ['who' => 'Ada']], triggerType: 'form', triggerNodeId: 'form');
        $fromManual = $this->runWorkflow($workflow);

        $this->assertSame('form core.form_trigger New hire', $this->stepFor($fromForm, 'say')->output['value']);
        $this->assertSame('manual core.manual_trigger ', $this->stepFor($fromManual, 'say')->output['value']);
        $this->assertSame(['values' => ['who' => 'Ada']], $this->stepFor($fromForm, 'form')->output, 'the trigger output stays what came in');
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

    public function test_a_node_runs_once_per_incoming_branch_unless_it_is_set_to_wait_for_all_of_them(): void
    {
        $graph = $this->graph(
            ['start' => 'start', 'fork' => 'fork', 'left' => 'emit', 'right' => 'pause', 'end' => ['emit', ['value' => 'done']]],
            ['start->fork', 'fork:a->left', 'fork:b->right', 'left->end', 'right->end'],
        );

        $eager = $this->runWorkflow($this->publishedWorkflow($graph));
        $this->assertSame(1, $eager->steps()->where('node_id', 'end')->where('status', StepStatus::Complete)->count());

        $graph['nodes'][4]['wait_for_all'] = true;
        $waiting = $this->runWorkflow($this->publishedWorkflow($graph));
        $this->assertSame(StepStatus::Waiting, $this->stepFor($waiting, 'end')->status);

        $this->travel(10)->minutes();
        $this->sweep();

        $this->assertSame(2, $eager->steps()->where('node_id', 'end')->count());
        $end = $this->stepFor($waiting->refresh(), 'end');
        $this->assertSame(ExecutionStatus::Complete, $waiting->status);
        $this->assertSame('done', $end->output['value']);
        $this->assertNull($end->state);
    }

    public function test_a_node_waiting_for_all_branches_proceeds_when_one_was_never_taken(): void
    {
        $graph = $this->graph(
            ['start' => 'start', 'check' => ['route', ['take' => 'true']], 'yes' => 'emit', 'no' => 'emit', 'end' => 'emit'],
            ['start->check', 'check:true->yes', 'check:false->no', 'yes->end', 'no->end'],
        );
        $graph['nodes'][4]['wait_for_all'] = true;

        $execution = $this->runWorkflow($this->publishedWorkflow($graph));

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(['start', 'check', 'yes', 'end'], $this->nodesRun($execution));
    }

    public function test_a_runs_output_leaves_out_every_branch_that_met_at_a_join(): void
    {
        $merged = $this->graph(
            ['start' => 'start', 'fork' => 'fork', 'left' => 'emit', 'right' => 'emit', 'merge' => 'join'],
            ['start->fork', 'fork:a->left', 'fork:b->right', 'left->merge', 'right->merge'],
        );
        $waited = $this->graph(
            ['start' => 'start', 'fork' => 'fork', 'left' => 'emit', 'right' => 'emit', 'end' => 'emit'],
            ['start->fork', 'fork:a->left', 'fork:b->right', 'left->end', 'right->end'],
        );
        $waited['nodes'][4]['wait_for_all'] = true;

        $this->assertSame(['merge'], array_keys($this->runWorkflow($this->publishedWorkflow($merged))->output));
        $this->assertSame(['end'], array_keys($this->runWorkflow($this->publishedWorkflow($waited))->output));
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
        $each = $this->stepFor($execution, 'each');
        $this->assertSame(['count' => 3], $each->output, 'results are read from the step rows, not copied into the loop row');
        $this->assertSame(['a', 'b', 'c'], array_map(fn ($result) => $result['body']['output']['item'], LoopResults::for($execution)->outputOf($each)['results']));
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

    public function test_a_loop_inside_an_iteration_carries_its_own_results(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'outer' => ['each', ['items' => [[1, 2], [3]]]], 'inner' => ['each', ['items_from_item' => true]], 'body' => 'emit'],
            ['start->outer', 'outer:item->inner', 'inner:item->body'],
        ));

        $execution = $this->runWorkflow($workflow);

        $outer = LoopResults::for($execution)->outputOf($this->stepFor($execution, 'outer'));
        $this->assertSame(2, $outer['count']);
        $this->assertSame([[1, 2], [3]], array_map(
            fn ($result) => array_map(fn ($inner) => $inner['body']['output']['item'], $result['inner']['output']['results']),
            $outer['results'],
        ));
    }

    public function test_a_step_inside_a_loop_sees_an_earlier_loops_results_but_not_its_own_earlier_iterations(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            [
                'start' => 'start',
                'first' => ['each', ['items' => ['a', 'b']]],
                'body' => 'emit',
                'second' => ['each', ['items' => ['x', 'y']]],
                'reader' => ['emit', ['value' => '{{ nodes.first.output.last.body.output.item }}']],
            ],
            ['start->first', 'first:item->body', 'first:done->second', 'second:item->reader'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $readers = $execution->steps()->where('node_id', 'reader')->orderBy('id')->get();
        $this->assertSame(['b', 'b'], $readers->map(fn ($step) => $step->output['value'])->all());
        $this->assertEqualsCanonicalizing(['start', 'first'], $readers[1]->output['saw'], 'the reader from the first iteration is not visible in the second');
    }

    public function test_a_run_that_ends_in_a_loop_returns_the_loops_results(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'each' => ['each', ['items' => ['a', 'b']]], 'body' => 'emit'],
            ['start->each', 'each:item->body'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(['each'], array_keys($execution->output));
        $this->assertSame('done', $execution->output['each']['port']);
        $this->assertSame(['a', 'b'], array_map(fn ($result) => $result['body']['output']['item'], $execution->output['each']['output']['results']));
    }

    public function test_loop_without_items_continues_straight_to_done(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'start', 'each' => ['each', ['items' => []]], 'body' => 'emit', 'after' => 'emit'],
            ['start->each', 'each:item->body', 'each:done->after'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(['start', 'each', 'after'], $this->nodesRun($execution));
        $this->assertSame(['results' => [], 'count' => 0, 'last' => null], LoopResults::for($execution)->outputOf($this->stepFor($execution, 'each')));
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
