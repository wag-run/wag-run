<?php

namespace Tests\Feature\Editor;

use App\Engine\Editor\DataPicker;
use App\Engine\Graph\GraphNode;
use App\Engine\Graph\WorkflowGraph;
use App\Enums\ValueType;
use App\Models\Secret;
use App\Models\StoreEntry;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\RunsWorkflows;
use Tests\TestCase;

class DataPickerTest extends TestCase
{
    use RefreshDatabase, RunsWorkflows;

    /**
     * @return array<string, list<string>>
     */
    private function paths(WorkflowGraph $graph, string $nodeId, ?Workspace $workspace = null): array
    {
        $groups = app(DataPicker::class)->optionsFor($graph, $nodeId, $workspace ?? Workspace::factory()->create());

        return collect($groups)->mapWithKeys(fn ($group) => [$group['label'] => array_column($group['items'], 'path')])->all();
    }

    public function test_offers_trigger_inputs_and_outputs_of_nodes_that_run_before(): void
    {
        $graph = WorkflowGraph::fromArray($this->graph(
            ['start' => ['core.manual_trigger', ['fields' => [['name' => 'order_id', 'label' => 'Order', 'type' => 'number']]]],
                'fetch' => 'core.http_request', 'later' => 'core.compose', 'other' => 'core.compose'],
            ['start->fetch', 'fetch->later', 'start->other'],
        ));
        $graph = $graph->withNode(new GraphNode('fetch', 'core.http_request', 'Fetch order'));

        $paths = $this->paths($graph, 'later');

        $this->assertSame(['trigger.values', 'trigger.values.order_id'], $paths['Trigger: start']);
        $this->assertSame(['nodes.fetch.output.status', 'nodes.fetch.output.ok', 'nodes.fetch.output.headers', 'nodes.fetch.output.body', 'nodes.fetch.output.file', 'nodes.fetch.output.file.name', 'nodes.fetch.output.file.mime', 'nodes.fetch.output.file.size'], $paths['Fetch order']);
        $this->assertArrayNotHasKey('other', $paths, 'parallel paths are not offered');
        $this->assertContains('execution.id', $paths['Execution']);
        $this->assertContains('execution.trigger.type', $paths['Execution']);
        $this->assertContains('execution.trigger.config', $paths['Execution']);
    }

    public function test_lists_the_closest_predecessor_first(): void
    {
        $graph = WorkflowGraph::fromArray($this->graph(
            ['start' => 'core.manual_trigger', 'first' => 'core.compose', 'second' => 'core.compose', 'third' => 'core.compose'],
            ['start->first', 'first->second', 'second->third'],
        ));
        $graph = $graph->withNode(new GraphNode('first', 'core.compose', 'First step'))->withNode(new GraphNode('second', 'core.compose', 'Second step'));

        $labels = array_keys($this->paths($graph, 'third'));

        $this->assertSame(['Second step', 'First step'], array_slice($labels, 0, 2));
        $this->assertSame('Trigger: start', $labels[2]);
    }

    public function test_lists_the_loop_item_before_the_trigger_for_a_node_inside_the_loop(): void
    {
        $graph = WorkflowGraph::fromArray($this->graph(
            ['start' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']], 'each' => ['core.for_loop', ['items' => '{{ trigger.body.items }}']], 'body' => 'core.compose'],
            ['start->each', 'each:item->body'],
        ));

        $labels = array_keys($this->paths($graph, 'body'));

        $this->assertLessThan(array_search('Trigger: start', $labels, true), array_search('Loop: each', $labels, true));
    }

    public function test_leaves_out_a_node_no_trigger_leads_to_until_it_is_connected(): void
    {
        $nodes = ['start' => 'core.manual_trigger', 'fetch' => 'core.http_request', 'bucket' => 'core.compose', 'upload' => 'core.compose'];
        $parked = WorkflowGraph::fromArray($this->graph($nodes, ['start->fetch', 'fetch->upload', 'bucket->upload']));
        $connected = WorkflowGraph::fromArray($this->graph($nodes, ['start->fetch', 'fetch->upload', 'bucket->upload', 'start->bucket']));

        $this->assertArrayNotHasKey('bucket', $this->paths($parked, 'upload'));
        $this->assertArrayHasKey('fetch', $this->paths($parked, 'upload'));
        $this->assertSame(['nodes.bucket.output.value'], $this->paths($connected, 'upload')['bucket']);
    }

    public function test_offers_the_loop_item_inside_a_loop_and_hides_loop_body_outputs_after_it(): void
    {
        $graph = WorkflowGraph::fromArray($this->graph(
            ['start' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']], 'each' => ['core.for_loop', ['items' => '{{ trigger.body.items }}']], 'body' => 'core.compose', 'after' => 'core.compose'],
            ['start->each', 'each:item->body', 'each:done->after'],
        ));

        $inside = $this->paths($graph, 'body');
        $after = $this->paths($graph, 'after');

        $this->assertSame(['item', 'loop.index', 'loop.count'], $inside['Loop: each']);
        $this->assertSame(['nodes.each.output.results', 'nodes.each.output.count', 'nodes.each.output.results[0].body.output.value'], $after['each']);
        $this->assertArrayNotHasKey('body', $after);
        $this->assertArrayNotHasKey('Loop: each', $after);
    }

    public function test_offers_a_loops_own_iteration_nodes_and_leaves_a_nested_loops_to_that_loop(): void
    {
        $graph = WorkflowGraph::fromArray($this->graph(
            ['start' => ['core.webhook_trigger', ['method' => 'POST', 'verification' => 'none']], 'outer' => ['core.for_loop', ['items' => '{{ trigger.body.groups }}']],
                'inner' => ['core.for_loop', ['items' => '{{ item }}']], 'deep' => 'core.compose', 'tail' => 'core.compose', 'after' => 'core.compose'],
            ['start->outer', 'outer:item->inner', 'inner:item->deep', 'inner:done->tail', 'outer:done->after'],
        ));

        $this->assertSame([
            'nodes.outer.output.results',
            'nodes.outer.output.count',
            'nodes.outer.output.results[0].inner.output.results',
            'nodes.outer.output.results[0].inner.output.count',
            'nodes.outer.output.results[0].tail.output.value',
        ], $this->paths($graph, 'after')['outer']);
    }

    public function test_offers_the_previous_pass_inside_a_while_loop_and_its_last_and_first_pass_after_it(): void
    {
        $graph = WorkflowGraph::fromArray($this->graph(
            ['start' => 'core.manual_trigger', 'pages' => ['core.while_loop', ['condition' => '{{ nodes.fetch.output.value }}']], 'fetch' => 'core.compose', 'keep' => 'core.compose', 'after' => 'core.compose'],
            ['start->pages', 'pages:pass->fetch', 'fetch->keep', 'pages:done->after'],
        ));

        $inside = $this->paths($graph, 'keep');
        $after = $this->paths($graph, 'after');

        $this->assertSame(['loop.index', 'loop.previous.fetch.output.value', 'loop.previous.keep.output.value', 'loop.results'], $inside['Loop: pages']);
        $this->assertSame(['nodes.fetch.output.value'], $inside['fetch']);
        $this->assertSame([
            'nodes.pages.output.last', 'nodes.pages.output.passes', 'nodes.pages.output.results',
            'nodes.pages.output.last.fetch.output.value', 'nodes.pages.output.results[0].fetch.output.value',
            'nodes.pages.output.last.keep.output.value', 'nodes.pages.output.results[0].keep.output.value',
        ], $after['pages']);
        $this->assertArrayNotHasKey('fetch', $after);
        $this->assertArrayNotHasKey('Loop: pages', $after);
    }

    public function test_a_while_loops_own_condition_is_offered_the_nodes_of_the_pass_it_checks(): void
    {
        $graph = WorkflowGraph::fromArray($this->graph(
            ['start' => 'core.manual_trigger', 'pages' => ['core.while_loop', ['condition' => '{{ nodes.fetch.output.value }}']], 'fetch' => 'core.compose'],
            ['start->pages', 'pages:pass->fetch'],
        ));

        $own = $this->paths($graph, 'pages');

        $this->assertSame(['nodes.fetch.output.value'], $own['Pass just finished › fetch']);
        $this->assertSame(['loop.index', 'loop.previous.fetch.output.value', 'loop.results'], $own['Loop: pages']);
    }

    public function test_inside_nested_loops_offers_loop_for_the_innermost_and_the_item_of_the_nearest_for_loop(): void
    {
        $graph = WorkflowGraph::fromArray($this->graph(
            ['start' => 'core.manual_trigger', 'each' => ['core.for_loop', ['items' => '{{ [1] }}']], 'poll' => ['core.while_loop', ['condition' => '{{ true }}']], 'check' => 'core.compose',
                'outer' => ['core.for_loop', ['items' => '{{ [1] }}']], 'inner' => ['core.for_loop', ['items' => '{{ item }}']], 'deep' => 'core.compose'],
            ['start->each', 'each:item->poll', 'poll:pass->check', 'start->outer', 'outer:item->inner', 'inner:item->deep'],
        ));

        $inWhile = $this->paths($graph, 'check');
        $inFor = $this->paths($graph, 'deep');

        $this->assertSame(['loop.index', 'loop.previous.check.output.value', 'loop.results'], $inWhile['Loop: poll']);
        $this->assertSame(['item'], $inWhile['Loop: each']);
        $this->assertSame(['item', 'loop.index', 'loop.count'], $inFor['Loop: inner']);
        $this->assertArrayNotHasKey('Loop: outer', $inFor);
    }

    public function test_offers_the_last_node_of_each_branch_a_merge_gathered(): void
    {
        $graph = WorkflowGraph::fromArray($this->graph(
            ['start' => 'core.manual_trigger', 'a' => 'core.compose', 'b' => 'core.compose', 'join' => 'core.merge', 'after' => 'core.compose'],
            ['start->a', 'start->b', 'a->join', 'b->join', 'join->after'],
        ));

        $this->assertSame(
            ['nodes.join.output.branches', 'nodes.join.output.branches.a.output.value', 'nodes.join.output.branches.b.output.value'],
            $this->paths($graph, 'after')['join'],
        );
    }

    public function test_offers_the_error_to_nodes_reached_through_an_error_output(): void
    {
        $graph = WorkflowGraph::fromArray($this->graph(
            ['start' => 'core.manual_trigger', 'fetch' => 'core.http_request', 'handle' => 'core.compose'],
            ['start->fetch', 'fetch:error->handle'],
        ));

        $this->assertSame(['error.message', 'error.attempt'], $this->paths($graph, 'handle')['Error']);
    }

    public function test_offers_data_store_entries_with_their_types_and_secret_names_only(): void
    {
        $workspace = Workspace::factory()->create();
        StoreEntry::factory()->for($workspace)->type(ValueType::Number)->create(['key' => 'attempts']);
        StoreEntry::factory()->for($workspace)->type(ValueType::Number)->collection()->create(['key' => 'failures']);
        Secret::factory()->for($workspace)->create(['key' => 'api_key', 'value' => 'hidden-value']);
        $graph = WorkflowGraph::fromArray($this->graph(['start' => 'core.manual_trigger', 'a' => 'core.compose'], ['start->a']));

        $paths = $this->paths($graph, 'a', $workspace);

        $this->assertSame(['store.attempts', 'store.failures'], $paths['Data store']);
        $this->assertSame(
            ['store.attempts' => 'number', 'store.failures' => 'object'],
            array_column(collect(app(DataPicker::class)->optionsFor($graph, 'a', $workspace))->firstWhere('label', 'Data store')['items'], 'type', 'path'),
        );
        $this->assertSame(['secrets.api_key'], $paths['Secrets']);
        $this->assertStringNotContainsString('hidden-value', json_encode(app(DataPicker::class)->optionsFor($graph, 'a', $workspace)));
    }

    public function test_structured_llm_fields_appear_for_later_nodes(): void
    {
        $graph = WorkflowGraph::fromArray($this->graph(
            ['start' => 'core.manual_trigger', 'ai' => ['core.llm', ['output' => 'structured', 'schema' => [['name' => 'sentiment', 'type' => 'string']]]], 'next' => 'core.compose'],
            ['start->ai', 'ai->next'],
        ));

        $this->assertContains('nodes.ai.output.data.sentiment', $this->paths($graph, 'next')['ai']);
    }

    public function test_offers_the_workflows_variables_with_their_types(): void
    {
        $graph = WorkflowGraph::fromArray([
            ...$this->graph(['start' => 'core.manual_trigger', 'a' => 'core.compose'], ['start->a']),
            'variables' => [['name' => 'attempts', 'type' => 'number', 'value' => '0'], ['name' => 'due', 'type' => 'datetime', 'value' => '']],
        ]);

        $group = collect(app(DataPicker::class)->optionsFor($graph, 'a', Workspace::factory()->create()))->firstWhere('label', 'Variables');

        $this->assertSame(['vars.attempts' => 'number', 'vars.due' => 'string'], array_column($group['items'], 'type', 'path'));
        $this->assertArrayNotHasKey('Variables', $this->paths(WorkflowGraph::fromArray($this->graph(['start' => 'core.manual_trigger', 'a' => 'core.compose'], ['start->a'])), 'a'));
    }

    public function test_a_starting_value_is_offered_the_triggers_the_data_store_and_the_run_only(): void
    {
        $workspace = Workspace::factory()->create();
        StoreEntry::factory()->for($workspace)->create(['key' => 'cursor']);
        Secret::factory()->for($workspace)->create(['key' => 'api_key']);
        $graph = WorkflowGraph::fromArray([
            ...$this->graph(
                ['start' => ['core.manual_trigger', ['fields' => [['name' => 'order_id', 'label' => 'Order', 'type' => 'number']]]],
                    'hook' => 'core.webhook_trigger', 'fetch' => 'core.http_request'],
                ['start->fetch', 'hook->fetch'],
            ),
            'variables' => [['name' => 'attempts', 'type' => 'number', 'value' => '0']],
        ]);

        $groups = collect(app(DataPicker::class)->optionsForStartingValue($graph, $workspace))
            ->mapWithKeys(fn ($group) => [$group['label'] => array_column($group['items'], 'path')]);

        $this->assertSame(['Trigger: start', 'Trigger: hook', 'Data store', 'Execution'], $groups->keys()->all());
        $this->assertContains('trigger.values.order_id', $groups['Trigger: start']);
        $this->assertContains('trigger.body', $groups['Trigger: hook']);
        $this->assertSame(['store.cursor'], $groups['Data store']);
        $this->assertContains('execution.id', $groups['Execution']);
    }
}
