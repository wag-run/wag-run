<?php

namespace Tests\Unit\Engine;

use App\Engine\Editor\NodeRenamer;
use App\Engine\Graph\GraphEdge;
use App\Engine\Graph\GraphNode;
use App\Engine\Graph\GraphVariable;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\NodeRegistry;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class WorkflowGraphTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function graphArray(): array
    {
        return [
            'version' => 1,
            'nodes' => [['id' => 'a', 'type' => 'core.compose', 'name' => 'A'], ['id' => 'b', 'type' => 'core.compose', 'name' => 'B']],
            'edges' => [['id' => 'e1', 'source' => 'a', 'source_port' => 'main', 'target' => 'b']],
            'variables' => [
                ['name' => 'attempts', 'type' => 'number', 'value' => '0'],
                ['name' => 'cursor', 'type' => 'string', 'value' => '{{ store.cursor }}'],
            ],
        ];
    }

    public function test_variables_round_trip_keyed_by_name(): void
    {
        $graph = WorkflowGraph::fromArray($this->graphArray());

        $this->assertSame(['attempts', 'cursor'], array_keys($graph->variables));
        $this->assertSame('{{ store.cursor }}', $graph->variables['cursor']->value);
        $this->assertSame($this->graphArray()['variables'], $graph->toArray()['variables']);
    }

    public function test_a_graph_without_variables_is_saved_without_the_key(): void
    {
        $graph = WorkflowGraph::fromArray([...$this->graphArray(), 'variables' => []]);

        $this->assertArrayNotHasKey('variables', $graph->toArray());
        $this->assertArrayNotHasKey('variables', $graph->withVariable(new GraphVariable('x'))->withoutVariable('x')->toArray());
        $this->assertSame([], WorkflowGraph::fromArray([...$this->graphArray(), 'variables' => 'not a list'])->variables);
    }

    public function test_declaring_a_variable_again_replaces_it(): void
    {
        $graph = WorkflowGraph::fromArray($this->graphArray())->withVariable(new GraphVariable('attempts', 'string', 'none'));

        $this->assertSame(['attempts', 'cursor'], array_keys($graph->variables));
        $this->assertSame('string', $graph->variables['attempts']->type);
    }

    public function test_every_change_to_nodes_and_connections_keeps_the_variables(): void
    {
        $graph = WorkflowGraph::fromArray($this->graphArray());
        $renamer = new NodeRenamer(new NodeRegistry(new Container));

        $changed = [
            'adding a node' => $graph->withNode(new GraphNode('c', 'core.compose', 'C')),
            'removing a node' => $graph->withoutNode('b'),
            'adding a connection' => $graph->withEdge(new GraphEdge('e2', 'b', 'a')),
            'removing a connection' => $graph->withoutEdge('a', 'main', 'b'),
            'dropping connections from old ports' => $graph->withoutEdgesFromPortsOtherThan('a', []),
            'renaming a node' => $renamer->rename($graph, 'b', 'c'),
        ];

        foreach ($changed as $change => $result) {
            $this->assertSame(['attempts', 'cursor'], array_keys($result->variables), $change);
        }
    }
}
