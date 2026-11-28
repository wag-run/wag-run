<?php

namespace Tests\Feature\Nodes;

use App\Engine\Graph\GraphValidator;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\NodeRegistry;
use App\Enums\ExecutionStatus;
use App\Enums\NodeKind;
use App\Enums\StepStatus;
use App\Models\Execution;
use App\Models\Organization;
use App\Models\StoreEntry;
use App\Models\Workspace;
use App\Providers\WorkflowServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class FlowNodesTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    public function test_every_built_in_node_is_registered_through_the_same_contract(): void
    {
        $registry = app(NodeRegistry::class);
        $types = array_map(fn (string $class) => $class::type(), WorkflowServiceProvider::BUILT_IN_NODES);

        $this->assertCount(47, array_unique($types));
        $this->assertSame(8, count($registry->ofKind(NodeKind::Trigger)));

        foreach ($types as $type) {
            $description = $registry->make($type)->describe();
            $this->assertSame($type, $description['type']);
            $this->assertNotSame('', $description['label']);
        }
    }

    public function test_conditional_follows_the_path_its_expression_chooses(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'check' => ['core.conditional', ['condition' => '{{ trigger.values.amount > 100 and trigger.values.currency == "EUR" }}']],
                'big' => ['core.compose', ['template' => 'big']], 'small' => ['core.compose', ['template' => 'small']]],
            ['start->check', 'check:true->big', 'check:false->small'],
        ));

        $this->assertSame(['start', 'check', 'big'], $this->nodesRun($this->runWorkflow($workflow, ['values' => ['amount' => 250, 'currency' => 'EUR']])));
        $this->assertSame(['start', 'check', 'small'], $this->nodesRun($this->runWorkflow($workflow, ['values' => ['amount' => 250, 'currency' => 'USD']])));
    }

    public function test_for_loop_runs_its_body_per_item_with_the_item_in_templates(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']], 'each' => ['core.for_loop', ['items' => '{{ trigger.body.orders }}']],
                'line' => ['core.compose', ['template' => '{{ item.qty * item.price }}']],
                'total' => ['core.compose', ['template' => "{{ sum(column(column(column(nodes.each.output.results, 'line'), 'output'), 'value')) }}"]]],
            ['start->each', 'each:item->line', 'each:done->total'],
        ));

        $execution = $this->runWorkflow($workflow, ['body' => ['orders' => [['qty' => 2, 'price' => 5], ['qty' => 1, 'price' => 7]]]]);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame([10, 7], $execution->steps()->where('node_id', 'line')->orderBy('id')->get()->map(fn ($step) => $step->output['value'])->all());
        $this->assertSame(17, $this->stepFor($execution, 'total')->output['value']);
    }

    public function test_for_loop_results_hold_every_node_of_each_iteration_in_the_same_shape_as_nodes(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']], 'each' => ['core.for_loop', ['items' => '{{ trigger.body.names }}']],
                'greet' => ['core.compose', ['template' => 'Hi {{ item }}']],
                'shout' => ['core.compose', ['template' => '{{ upper(nodes.greet.output.value) }}']],
                'after' => ['core.compose', ['template' => '{{ nodes.each.output.results[1].greet.output.value }} / {{ nodes.each.output.results[1].shout.output.value }} / {{ nodes.each.output.results[0].greet.port }} / {{ nodes.each.output.count }} / {{ nodes.each.output.last.shout.output.value }}']]],
            ['start->each', 'each:item->greet', 'greet->shout', 'each:done->after'],
        ));

        $execution = $this->runWorkflow($workflow, ['body' => ['names' => ['Ada', 'Grace']]]);

        $this->assertSame('Hi Grace / HI GRACE / main / 2 / HI GRACE', $this->stepFor($execution, 'after')->output['value']);
    }

    public function test_for_loop_refuses_a_value_that_is_not_a_list(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'each' => ['core.for_loop', ['items' => '{{ trigger.values.name }}']]],
            ['start->each'],
        ));

        $execution = $this->runWorkflow($workflow, ['values' => ['name' => 'not a list']]);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('Items must be an array.', $execution->error['message']);
    }

    public function test_while_loop_repeats_its_body_while_the_condition_is_true_and_reads_the_previous_pass(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'pages' => ['core.while_loop', ['condition' => '{{ nodes.fetch == null or nodes.fetch.output.value < 3 }}']],
                'fetch' => ['core.compose', ['template' => '{{ (loop.previous.fetch.output.value ?? 0) + 1 }}']],
                'seen' => ['core.compose', ['template' => '{{ loop.index }}:{{ loop.previous.fetch.output.value ?? "none" }}']],
                'after' => ['core.compose', ['template' => '{{ nodes.pages.output.count }} / {{ nodes.pages.output.last.fetch.output.value }} / {{ nodes.pages.output.results[0].fetch.output.value }} / {{ nodes.pages.output.results[1].seen.output.value }} / {{ nodes.pages.output.last.fetch.port }}']]],
            ['start->pages', 'pages:pass->fetch', 'fetch->seen', 'pages:done->after'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame([1, 2, 3], $execution->steps()->where('node_id', 'fetch')->orderBy('id')->get()->map(fn ($step) => $step->output['value'])->all());
        $this->assertSame(['0:none', '1:1', '2:2'], $execution->steps()->where('node_id', 'seen')->orderBy('id')->get()->map(fn ($step) => $step->output['value'])->all());
        $this->assertSame('3 / 3 / 1 / 1:1 / main', $this->stepFor($execution, 'after')->output['value']);
        $this->assertSame(['count' => 3], $this->stepFor($execution, 'pages')->output);
    }

    public function test_while_loop_skips_its_body_when_the_condition_is_false_before_the_first_pass(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'pages' => ['core.while_loop', ['condition' => '{{ trigger.values.go }}']],
                'body' => ['core.compose', ['template' => 'ran']],
                'after' => ['core.compose', ['template' => '{{ nodes.pages.output.count }}|{{ nodes.pages.output.last == null }}|{{ length(nodes.pages.output.results) }}']]],
            ['start->pages', 'pages:pass->body', 'pages:done->after'],
        ));

        $execution = $this->runWorkflow($workflow, ['values' => ['go' => false]]);

        $this->assertSame(['start', 'pages', 'after'], $this->nodesRun($execution));
        $this->assertSame('0|true|0', $this->stepFor($execution, 'after')->output['value']);
    }

    public function test_while_loop_that_checks_after_each_pass_runs_at_least_once(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'pages' => ['core.while_loop', ['condition' => '{{ trigger.values.go }}', 'check_after' => true]],
                'body' => ['core.compose', ['template' => 'ran']]],
            ['start->pages', 'pages:pass->body'],
        ));

        $execution = $this->runWorkflow($workflow, ['values' => ['go' => false]]);

        $this->assertSame(['start', 'pages', 'body'], $this->nodesRun($execution));
        $this->assertSame(1, $execution->output['pages']['output']['count']);
        $this->assertSame('ran', $execution->output['pages']['output']['last']['body']['output']['value']);
    }

    public function test_while_loop_fails_when_its_condition_is_still_true_after_the_maximum_passes(): void
    {
        $graph = fn (array $edges) => $this->graph(
            ['start' => 'core.manual_trigger', 'spin' => ['core.while_loop', ['condition' => '{{ true }}', 'max_passes' => 3]],
                'body' => ['core.compose', ['template' => '{{ loop.index }}']],
                'caught' => ['core.compose', ['template' => '{{ error.message }}']], 'after' => ['core.compose', ['template' => 'done']]],
            ['start->spin', 'spin:pass->body', 'spin:done->after', ...$edges],
        );

        $failed = $this->runWorkflow($this->publishedWorkflow($graph([])));

        $this->assertSame(ExecutionStatus::Failed, $failed->status);
        $this->assertSame('The condition was still true after 3 passes, the most this loop may run.', $failed->error['message']);
        $this->assertSame(3, $failed->steps()->where('node_id', 'body')->count());
        $this->assertSame(StepStatus::Failed, $this->stepFor($failed, 'spin')->status);

        $caught = $this->runWorkflow($this->publishedWorkflow($graph(['spin:error->caught'])));

        $this->assertSame(ExecutionStatus::Complete, $caught->status);
        $this->assertSame('The condition was still true after 3 passes, the most this loop may run.', $this->stepFor($caught, 'caught')->output['value']);
        $this->assertSame(0, $caught->steps()->where('node_id', 'after')->count());
    }

    public function test_while_loop_inside_a_for_loop_reads_the_item_and_its_own_passes(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'each' => ['core.for_loop', ['items' => '{{ [2, 1] }}']],
                'poll' => ['core.while_loop', ['condition' => '{{ nodes.tick.output.value < item }}', 'check_after' => true]],
                'tick' => ['core.compose', ['template' => '{{ loop.index + 1 }}']],
                'after' => ['core.compose', ['template' => "{{ join(column(column(column(nodes.each.output.results, 'poll'), 'output'), 'count'), ',') }}"]]],
            ['start->each', 'each:item->poll', 'poll:pass->tick', 'each:done->after'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(ExecutionStatus::Complete, $execution->status);
        $this->assertSame([1, 2, 1], $execution->steps()->where('node_id', 'tick')->orderBy('id')->get()->map(fn ($step) => $step->output['value'])->all());
        $this->assertSame('2,1', $this->stepFor($execution, 'after')->output['value']);
    }

    public function test_while_loop_reports_a_condition_that_is_not_one_placeholder_and_a_maximum_out_of_range(): void
    {
        $node = app(NodeRegistry::class)->make('core.while_loop');

        $this->assertSame([], $node->configProblems(['condition' => '{{ vars.more }}', 'max_passes' => '250']));
        $this->assertSame([], $node->configProblems(['condition' => '{{ vars.more }}']));
        $this->assertSame(['its condition must be one {{ }} placeholder, such as {{ nodes.fetch.output.body.next_cursor }}.'], $node->configProblems(['condition' => 'yes {{ vars.more }}']));
        $this->assertSame(['its maximum passes must be a whole number from 1 to 10,000.'], $node->configProblems(['condition' => '{{ vars.more }}', 'max_passes' => 10001]));
        $this->assertSame(['its maximum passes must be a whole number from 1 to 10,000.'], $node->configProblems(['condition' => '{{ vars.more }}', 'max_passes' => 0]));
    }

    public function test_while_loop_is_called_do_while_loop_when_it_checks_after_each_pass(): void
    {
        $node = app(NodeRegistry::class)->make('core.while_loop');
        $condition = collect($node->inputs())->firstWhere('name', 'condition');

        $this->assertSame('While loop', $node->label());
        $this->assertSame('While loop', $node->labelFor([]));
        $this->assertSame('Do while loop', $node->labelFor(['check_after' => true]));
        $this->assertStringContainsString('Checked before each pass, so the loop may not run at all if false.', $node->fieldHelp($condition, []));
        $this->assertStringContainsString('Checked after each pass, so the loop runs at least once.', $node->fieldHelp($condition, ['check_after' => '1']));
        $this->assertSame('each pass', $node->portLabel('pass'));
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

        $this->assertSame([
            'a' => ['output' => ['value' => 'A'], 'port' => 'main'],
            'b' => ['output' => ['value' => 'B'], 'port' => 'main'],
            'c' => ['output' => ['value' => 'C'], 'port' => 'main'],
        ], $this->stepFor($execution, 'join')->output['branches']);
        $this->assertSame(1, $execution->steps()->where('node_id', 'join')->count());
    }

    /**
     * @return array<string, array{string, bool, string}>
     */
    public static function switchValues(): array
    {
        return [
            'first equal case wins' => ['pro', false, 'b'],
            'case ignored' => ['PRO', true, 'b'],
            'case matters by default' => ['PRO', false, 'other'],
            'no case equals' => ['enterprise', false, 'other'],
        ];
    }

    #[DataProvider('switchValues')]
    public function test_switch_follows_the_first_case_equal_to_the_value_or_default(string $plan, bool $ignoreCase, string $expected): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'route' => ['core.switch', ['value' => '{{ trigger.values.plan }}', 'ignore_case' => $ignoreCase, 'cases' => [['value' => 'free'], ['value' => 'pro'], ['value' => 'pro']]]],
                'a' => 'core.compose', 'b' => 'core.compose', 'c' => 'core.compose', 'other' => 'core.compose'],
            ['start->route', 'route:case_1->a', 'route:case_2->b', 'route:case_3->c', 'route:default->other'],
        ));

        $execution = $this->runWorkflow($workflow, ['values' => ['plan' => $plan]]);

        $this->assertSame(['start', 'route', $expected], $this->nodesRun($execution));
    }

    public function test_wait_pauses_for_its_duration(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'nap' => ['core.wait', ['mode' => 'duration', 'amount' => 2, 'unit' => 'hours']], 'after' => ['core.compose', ['template' => 'done']]],
            ['start->nap', 'nap->after'],
        ));

        $execution = $this->runWorkflow($workflow);
        $this->assertSame(ExecutionStatus::Waiting, $execution->status);

        $this->travel(119)->minutes();
        $this->sweep();
        $this->assertSame(ExecutionStatus::Waiting, $execution->refresh()->status, 'not two hours yet');

        $this->travel(2)->minutes();
        $this->sweep();

        $this->assertSame(ExecutionStatus::Complete, $execution->refresh()->status);
    }

    public function test_wait_until_a_past_time_continues_immediately(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'nap' => ['core.wait', ['mode' => 'until', 'until' => '{{ trigger.values.at }}']]],
            ['start->nap'],
        ));

        $this->assertSame(ExecutionStatus::Complete, $this->runWorkflow($workflow, ['values' => ['at' => '2020-01-01 00:00']])->status);
    }

    public function test_compose_builds_values_from_templates_expressions_and_json(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger',
                'greeting' => ['core.compose', ['template' => 'Hi {{ title(trigger.values.name) }} from {{ store.team }}']],
                'math' => ['core.compose', ['template' => '{{ round(trigger.values.amount * 1.2, 2) }}']],
                'json' => ['core.compose', ['template' => '{"id": {{ trigger.values.id }}, "ok": true}', 'parse_json' => true]]],
            ['start->greeting', 'greeting->math', 'math->json'],
        ));
        StoreEntry::factory()->for($workflow->workspace)->holding('Ops')->create(['key' => 'team']);

        $execution = $this->runWorkflow($workflow, ['values' => ['name' => 'ada lovelace', 'amount' => 10.5, 'id' => 7]]);

        $this->assertSame('Hi Ada Lovelace from Ops', $this->stepFor($execution, 'greeting')->output['value']);
        $this->assertSame(12.6, $this->stepFor($execution, 'math')->output['value']);
        $this->assertSame(['id' => 7, 'ok' => true], $this->stepFor($execution, 'json')->output['value']);
    }

    public function test_template_errors_fail_the_step_without_retrying(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'bad' => ['core.wait', ['mode' => 'until', 'until' => '{{ nope(trigger.values.at) }}'], ['max_retries' => 3]]],
            ['start->bad'],
        ));

        $execution = $this->runWorkflow($workflow);

        $this->assertSame(1, $execution->steps()->where('node_id', 'bad')->count());
        $this->assertStringContainsString('Could not resolve inputs: Expression error', $execution->error['message']);
    }

    public function test_terminate_ends_the_run_with_its_status_and_output(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'stop' => ['core.terminate', ['status' => 'cancelled', 'output' => 'stopped for {{ trigger.values.reason }}']], 'never' => 'core.compose'],
            ['start->stop'],
        ));

        $execution = $this->runWorkflow($workflow, ['values' => ['reason' => 'duplicate']]);

        $this->assertSame(ExecutionStatus::Cancelled, $execution->status);
        $this->assertSame('stopped for duplicate', $execution->output);
    }

    public function test_trigger_other_workflow_in_sync_mode_returns_its_output(): void
    {
        $workspace = Workspace::factory()->create();
        $child = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'reply' => ['core.compose', ['template' => 'hello {{ trigger.values.name }}']]],
            ['start->reply'],
        ), $workspace);
        $parent = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.run_workflow', ['workflow' => $child->id, 'mode' => 'sync', 'input' => ['name' => '{{ trigger.values.who }}']]],
                'use' => ['core.compose', ['template' => '{{ nodes.call.output.results.reply.output.value }}!']]],
            ['start->call', 'call->use'],
        ), $workspace);

        $execution = $this->runWorkflow($parent, ['values' => ['who' => 'grace']]);

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

    public function test_trigger_other_workflow_fails_when_a_required_input_of_its_trigger_is_missing(): void
    {
        $workspace = Workspace::factory()->create();
        $fields = [['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true], ['name' => 'note', 'label' => 'Note', 'type' => 'text']];
        $child = $this->publishedWorkflow($this->graph(
            ['start' => ['core.manual_trigger', ['fields' => $fields]], 'reply' => ['core.compose', ['template' => 'hi {{ trigger.values.email }}']]],
            ['start->reply'],
        ), $workspace);
        $parent = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.run_workflow', ['workflow' => $child->id, 'mode' => 'sync', 'input' => ['email' => '{{ trigger.values.email }}', 'note' => 'x']]]],
            ['start->call'],
        ), $workspace);

        $execution = $this->runWorkflow($parent, ['values' => ['email' => '']]);

        $message = 'The trigger requires an input that was not given: Email.';
        $childRun = Execution::where('workflow_id', $child->id)->sole();
        $this->assertSame(ExecutionStatus::Failed, $childRun->status);
        $this->assertSame(['message' => $message, 'code' => 'required_input_missing'], $childRun->error);
        $this->assertSame(0, $childRun->steps()->count(), 'the child fails before its first step');
        $this->assertSame(StepStatus::Failed, $this->stepFor($execution, 'call')->status);
        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame($message, $execution->error['message']);

        $this->assertSame(ExecutionStatus::Complete, $this->runWorkflow($parent, ['values' => ['email' => 'ada@example.com']])->status);
    }

    public function test_trigger_other_workflow_in_async_mode_fails_when_a_required_input_is_missing(): void
    {
        $workspace = Workspace::factory()->create();
        $child = $this->publishedWorkflow($this->graph(['start' => ['core.manual_trigger', ['fields' => [['name' => 'id', 'label' => 'Order ID', 'type' => 'text', 'required' => true]]]]]), $workspace);
        $parent = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.run_workflow', ['workflow' => $child->id, 'mode' => 'async']]],
            ['start->call'],
        ), $workspace);

        $execution = $this->runWorkflow($parent);

        $this->assertSame(ExecutionStatus::Failed, Execution::where('workflow_id', $child->id)->sole()->status);
        $this->assertSame(StepStatus::Failed, $this->stepFor($execution, 'call')->status);
        $this->assertSame('The trigger requires an input that was not given: Order ID.', $execution->error['message']);
    }

    public function test_trigger_other_workflow_checks_the_required_fields_of_a_form_trigger_too(): void
    {
        $workspace = Workspace::factory()->create();
        $form = ['title' => 'Signup', 'fields' => [['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]]];
        $child = $this->publishedWorkflow($this->graph(
            ['signup' => ['core.form_trigger', $form], 'reply' => ['core.compose', ['template' => 'hi {{ trigger.values.email }}']]],
            ['signup->reply'],
        ), $workspace);
        $parent = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.run_workflow', ['workflow' => $child->id, 'mode' => 'sync', 'input' => ['email' => '{{ trigger.values.email }}']]],
                'use' => ['core.compose', ['template' => '{{ nodes.call.output.results.reply.output.value }}']]],
            ['start->call', 'call->use'],
        ), $workspace);

        $this->assertSame('The trigger requires an input that was not given: Email.', $this->runWorkflow($parent, ['values' => []])->error['message']);
        $this->assertSame('hi ada@example.com', $this->stepFor($this->runWorkflow($parent, ['values' => ['email' => 'ada@example.com']]), 'use')->output['value']);
    }

    public function test_trigger_other_workflow_refuses_to_start_its_own_workflow(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger']));
        $workflow->publishedVersion->update(['graph' => $this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.run_workflow', ['workflow' => $workflow->id, 'mode' => 'async']]],
            ['start->call'],
        )]);

        $execution = $this->runWorkflow($workflow->refresh());

        $this->assertSame('A workflow cannot trigger itself.', $execution->error['message']);
        $this->assertSame(1, $workflow->executions()->count());
    }

    public function test_workflows_starting_each_other_stop_at_the_maximum_chain_depth(): void
    {
        Organization::factory()->create(['max_chain_depth' => 3]);
        $workspace = Workspace::factory()->create();
        $first = $this->publishedWorkflow($this->graph(['start' => 'core.manual_trigger']), $workspace);
        $second = $this->publishedWorkflow($this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.run_workflow', ['workflow' => $first->id, 'mode' => 'async']]],
            ['start->call'],
        ), $workspace);
        $first->publishedVersion->update(['graph' => $this->graph(
            ['start' => 'core.manual_trigger', 'call' => ['core.run_workflow', ['workflow' => $second->id, 'mode' => 'sync']]],
            ['start->call'],
        )]);

        $this->runWorkflow($first->refresh());

        $runs = Execution::query()->orderBy('id')->get();
        $message = 'Stopped: this run would be more than 3 levels deep in a chain of workflows starting workflows. Check for workflows that start each other.';
        $this->assertSame([1, 2, 3, 4], $runs->pluck('chain_depth')->all());
        $this->assertSame([$first->id, $second->id, $first->id, $second->id], $runs->pluck('workflow_id')->all());
        $this->assertSame(ExecutionStatus::Cancelled, $runs[3]->status);
        $this->assertSame($message, $runs[3]->error['message']);
        $this->assertSame(ExecutionStatus::Failed, $runs[2]->status, 'the run waiting on the stopped one fails with its reason');
        $this->assertSame($message, $runs[2]->error['message']);
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

    public function test_filter_keeps_the_items_that_match_and_lists_the_rest(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']], 'keep' => ['core.filter', ['items' => '{{ trigger.body.orders }}', 'condition' => '{{ item.amount > 100 and index < 2 }}']]],
            ['start->keep'],
        ));

        $execution = $this->runWorkflow($workflow, ['body' => ['orders' => [['amount' => 50], ['amount' => 150], ['amount' => 300]]]]);

        $this->assertSame(
            ['items' => [['amount' => 150]], 'count' => 1, 'removed' => [['amount' => 50], ['amount' => 300]]],
            $this->stepFor($execution, 'keep')->output,
        );
    }

    public function test_filter_inside_a_for_each_still_reaches_the_loop_item_as_loop_item(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']],
                'each' => ['core.for_loop', ['items' => '{{ trigger.body.limits }}']],
                'keep' => ['core.filter', ['items' => '{{ trigger.body.amounts }}', 'condition' => '{{ item > loop.item }}']]],
            ['start->each', 'each:item->keep'],
        ));

        $execution = $this->runWorkflow($workflow, ['body' => ['limits' => [10, 20], 'amounts' => [5, 15, 25]]]);

        $this->assertSame(
            [[15, 25], [25]],
            $execution->steps()->where('node_id', 'keep')->orderBy('id')->get()->map(fn ($step) => $step->output['items'])->all(),
        );
    }

    public function test_filter_fails_when_it_is_not_given_a_list(): void
    {
        $workflow = $this->publishedWorkflow($this->graph(
            ['start' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']], 'keep' => ['core.filter', ['items' => '{{ trigger.body.order }}', 'condition' => '{{ item.amount > 100 }}']]],
            ['start->keep'],
        ));

        $execution = $this->runWorkflow($workflow, ['body' => ['order' => ['amount' => 150]]]);

        $this->assertSame(ExecutionStatus::Failed, $execution->status);
        $this->assertSame('The array to filter is array, not an array.', $execution->error['message']);
    }

    public function test_publishing_refuses_a_filter_whose_list_or_condition_is_not_one_placeholder(): void
    {
        $problems = app(GraphValidator::class)->validate(WorkflowGraph::fromArray($this->graph(
            ['start' => 'core.manual_trigger', 'keep' => ['core.filter', ['items' => 'orders', 'condition' => 'item.amount > 100']]],
            ['start->keep'],
        )));

        $this->assertContains('Node "keep": its array must be one {{ }} placeholder, such as {{ nodes.fetch.output.body.items }}.', $problems);
        $this->assertContains('Node "keep": its condition must be one {{ }} placeholder, such as {{ item.amount > 100 }}.', $problems);
    }
}
