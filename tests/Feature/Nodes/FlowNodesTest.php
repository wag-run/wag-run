<?php

namespace Tests\Feature\Nodes;

use App\Engine\NodeRegistry;
use App\Enums\ExecutionStatus;
use App\Enums\NodeKind;
use App\Enums\StepStatus;
use App\Models\Execution;
use App\Models\Workspace;
use App\Providers\WorkflowServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class FlowNodesTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    public function test_every_built_in_node_is_registered_through_the_same_contract(): void
    {
        $registry = app(NodeRegistry::class);
        $types = array_map(fn (string $class) => $class::type(), WorkflowServiceProvider::BUILT_IN_NODES);

        $this->assertCount(15, array_unique($types));
        $this->assertSame(4, count($registry->ofKind(NodeKind::Trigger)));

        foreach ($types as $type) {
            $description = $registry->make($type)->describe();
            $this->assertSame($type, $description['type']);
            $this->assertNotSame('', $description['label']);
        }
    }

    public function test_conditional_follows_the_path_its_expression_chooses(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'check' => ['core.conditional', ['condition' => 'trigger.amount > 100 and trigger.currency == "EUR"']],
                'big' => ['core.compose', ['template' => 'big']], 'small' => ['core.compose', ['template' => 'small']]],
            ['start->check', 'check:true->big', 'check:false->small'],
        ));

        $this->assertSame(['start', 'check', 'big'], $this->nodesRun($this->runWorkflow($workflow, ['amount' => 250, 'currency' => 'EUR'])));
        $this->assertSame(['start', 'check', 'small'], $this->nodesRun($this->runWorkflow($workflow, ['amount' => 250, 'currency' => 'USD'])));
    }

    public function test_for_loop_runs_its_body_per_item_with_the_item_in_templates(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'each' => ['core.for_loop', ['items' => '{{ trigger.orders }}']],
                'line' => ['core.compose', ['mode' => 'expression', 'expression' => 'item.qty * item.price']],
                'total' => ['core.compose', ['template' => '{{ nodes.each.output.results|map(r => r.line.value)|reduce((a, b) => a + b) }}']]],
            ['start->each', 'each:item->line', 'each:done->total'],
        ));

        $execution = $this->runWorkflow($workflow, ['orders' => [['qty' => 2, 'price' => 5], ['qty' => 1, 'price' => 7]]]);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame([10, 7], $execution->steps()->where('node_id', 'line')->orderBy('id')->get()->map(fn ($step) => $step->output['value'])->all());
        $this->assertSame(17, $this->stepFor($execution, 'total')->output['value']);
    }

    public function test_for_loop_refuses_a_value_that_is_not_a_list(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'each' => ['core.for_loop', ['items' => '{{ trigger.name }}']]],
            ['start->each'],
        ));

        $execution = $this->runWorkflow($workflow, ['name' => 'not a list']);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('Items must be a list.', $execution->error['message']);
    }

    public function test_every_connection_from_one_output_runs_and_merge_collects_their_outputs(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'a' => ['core.compose', ['template' => 'A']], 'b' => ['core.compose', ['template' => 'B']], 'c' => ['core.compose', ['template' => 'C']],
                'join' => 'core.merge'],
            ['start->a', 'start->b', 'start->c', 'a->join', 'b->join', 'c->join'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(['a' => ['value' => 'A'], 'b' => ['value' => 'B'], 'c' => ['value' => 'C']], $this->stepFor($execution, 'join')->output['branches']);
        $this->assertSame(1, $execution->steps()->where('node_id', 'join')->count());
    }

    public function test_wait_pauses_for_its_duration(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'nap' => ['core.wait', ['mode' => 'duration', 'amount' => 2, 'unit' => 'hours']], 'after' => ['core.compose', ['template' => 'done']]],
            ['start->nap', 'nap->after'],
        ));

        $execution = $this->runWorkflow($workflow);
        $this->assertSame(ExecutionStatus::Waiting, $execution->status);

        $this->travel(2)->hours();
        $this->travel(1)->minute();
        $this->sweep();

        $this->assertSame(ExecutionStatus::Complete, $execution->refresh()->status);
    }

    public function test_wait_until_a_past_time_continues_immediately(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'nap' => ['core.wait', ['mode' => 'until', 'until' => '{{ trigger.at }}']]],
            ['start->nap'],
        ));

        $this->assertSame(ExecutionStatus::Complete, $this->runWorkflow($workflow, ['at' => '2020-01-01 00:00'])->status);
    }

    public function test_compose_builds_values_from_templates_expressions_and_json(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'greeting' => ['core.compose', ['template' => 'Hi {{ trigger.name|title }} from {{ vars.team }}']],
                'math' => ['core.compose', ['mode' => 'expression', 'expression' => 'round(trigger.amount * 1.2, 2)']],
                'json' => ['core.compose', ['template' => '{"id": {{ trigger.id }}, "ok": true}', 'parse_json' => true]]],
            ['start->greeting', 'greeting->math', 'math->json'],
        ));
        $workflow->workspace->variables()->create(['key' => 'team', 'value' => 'Ops']);

        $execution = $this->runWorkflow($workflow, ['name' => 'ada lovelace', 'amount' => 10.5, 'id' => 7]);

        $this->assertSame('Hi Ada Lovelace from Ops', $this->stepFor($execution, 'greeting')->output['value']);
        $this->assertSame(12.6, $this->stepFor($execution, 'math')->output['value']);
        $this->assertSame(['id' => 7, 'ok' => true], $this->stepFor($execution, 'json')->output['value']);
    }

    public function test_template_errors_fail_the_step_without_retrying(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'bad' => ['core.wait', ['mode' => 'until', 'until' => '{{ trigger.at|nope }}'], ['max_retries' => 3]]],
            ['start->bad'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(1, $execution->steps()->where('node_id', 'bad')->count());
        $this->assertStringContainsString('Could not resolve inputs: Template error', $execution->error['message']);
    }

    public function test_terminate_ends_the_run_with_its_status_and_output(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'stop' => ['core.terminate', ['status' => 'cancelled', 'output' => 'stopped for {{ trigger.reason }}']], 'never' => 'core.compose'],
            ['start->stop'],
        ));

        $execution = $this->runWorkflow($workflow, ['reason' => 'duplicate']);

        $this->assertSame(ExecutionStatus::Cancelled, $execution->status);
        $this->assertSame('stopped for duplicate', $execution->output);
    }

    public function test_trigger_other_workflow_in_sync_mode_returns_its_output(): void
    {
        $workspace = Workspace::factory()->create();
        $child = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'reply' => ['core.compose', ['template' => 'hello {{ trigger.name }}']]],
            ['start->reply'],
        ), $workspace);
        $parent = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.run_workflow', ['workflow' => $child->id, 'mode' => 'sync', 'input' => ['name' => '{{ trigger.who }}']]],
                'use' => ['core.compose', ['template' => '{{ nodes.call.output.output.reply.value }}!']]],
            ['start->call', 'call->use'],
        ), $workspace);

        $execution = $this->runWorkflow($parent, ['who' => 'grace']);

        $this->assertSame('hello grace!', $this->stepFor($execution, 'use')->output['value']);
    }

    public function test_trigger_other_workflow_in_async_mode_moves_on(): void
    {
        $workspace = Workspace::factory()->create();
        $child = $this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger', 'nap' => ['core.wait', ['amount' => 1, 'unit' => 'hours']]], ['start->nap']), $workspace);
        $parent = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.run_workflow', ['workflow' => $child->id, 'mode' => 'async']]],
            ['start->call'],
        ), $workspace);

        $execution = $this->runWorkflow($parent);

        $childRun = Execution::where('workflow_id', $child->id)->sole();
        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame(ExecutionStatus::Waiting, $childRun->status);
        $this->assertNull($childRun->parent_step_id);
        $this->assertSame($childRun->id, $this->stepFor($execution, 'call')->output['execution_id']);
    }

    public function test_trigger_other_workflow_refuses_a_workflow_from_another_workspace(): void
    {
        $foreign = $this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger']));
        $parent = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.run_workflow', ['workflow' => $foreign->id]]],
            ['start->call'],
        ));

        $execution = $this->runWorkflow($parent);

        $this->assertSame(StepStatus::Failed, $this->stepFor($execution, 'call')->status);
        $this->assertSame('The workflow to trigger does not exist in this workspace.', $execution->error['message']);
        $this->assertSame(0, $foreign->executions()->count());
    }
}
