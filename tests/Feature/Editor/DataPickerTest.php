<?php

namespace Tests\Feature\Editor;

use App\Engine\Editor\DataPicker;
use App\Engine\Graph\GraphNode;
use App\Engine\Graph\WorkflowGraph;
use App\Models\Secret;
use App\Models\Variable;
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

        $this->assertSame(['trigger.order_id'], $paths['Trigger: start']);
        $this->assertSame(['nodes.fetch.output.status', 'nodes.fetch.output.ok', 'nodes.fetch.output.headers', 'nodes.fetch.output.body', 'nodes.fetch.output.file', 'nodes.fetch.output.file.name', 'nodes.fetch.output.file.mime', 'nodes.fetch.output.file.size'], $paths['Fetch order']);
        $this->assertArrayNotHasKey('other', $paths, 'parallel paths are not offered');
        $this->assertContains('execution.id', $paths['Execution']);
    }

    public function test_offers_the_loop_item_inside_a_loop_and_hides_loop_body_outputs_after_it(): void
    {
        $graph = WorkflowGraph::fromArray($this->graph(
            ['start' => 'core.manual_trigger', 'each' => ['core.for_loop', ['items' => '{{ trigger.items }}']], 'body' => 'core.compose', 'after' => 'core.compose'],
            ['start->each', 'each:item->body', 'each:done->after'],
        ));

        $inside = $this->paths($graph, 'body');
        $after = $this->paths($graph, 'after');

        $this->assertSame(['item', 'loop.index', 'loop.count'], $inside['Loop: each']);
        $this->assertSame(['nodes.each.output.results'], $after['each']);
        $this->assertArrayNotHasKey('body', $after);
        $this->assertArrayNotHasKey('Loop: each', $after);
    }

    public function test_offers_the_error_to_nodes_reached_through_an_error_output(): void
    {
        $graph = WorkflowGraph::fromArray($this->graph(
            ['start' => 'core.manual_trigger', 'fetch' => 'core.http_request', 'handle' => 'core.compose'],
            ['start->fetch', 'fetch:error->handle'],
        ));

        $this->assertSame(['error.message', 'error.attempt'], $this->paths($graph, 'handle')['Error']);
    }

    public function test_offers_workspace_variable_and_secret_names_only(): void
    {
        $workspace = Workspace::factory()->create();
        Variable::factory()->for($workspace)->create(['key' => 'region']);
        Secret::factory()->for($workspace)->create(['key' => 'api_key', 'value' => 'hidden-value']);
        $graph = WorkflowGraph::fromArray($this->graph(['start' => 'core.manual_trigger', 'a' => 'core.compose'], ['start->a']));

        $paths = $this->paths($graph, 'a', $workspace);

        $this->assertSame(['vars.region'], $paths['Variables']);
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
}
