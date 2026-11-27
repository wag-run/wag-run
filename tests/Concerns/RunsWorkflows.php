<?php

namespace Tests\Concerns;

use App\Engine\Engine;
use App\Engine\NodeRegistry;
use App\Engine\Sweeper;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\Workflow;
use App\Models\Workspace;
use Tests\Fixtures\Nodes;

trait RunsWorkflows
{
    protected function registerFixtureNodes(): void
    {
        $registry = $this->app->make(NodeRegistry::class);

        foreach ([Nodes\Start::class, Nodes\Emit::class, Nodes\Route::class, Nodes\Fork::class, Nodes\Join::class, Nodes\Flaky::class,
            Nodes\Pause::class, Nodes\Ask::class, Nodes\Each::class, Nodes\Again::class, Nodes\Stop::class, Nodes\CallWorkflow::class] as $class) {
            $registry->register($class);
        }
    }

    /**
     * Build a graph. Nodes map an id to a type, or to [type, config, retry].
     * A type without a dot is a test fixture: "emit" means "test.emit".
     * Edges are "a->b" or "a:port->b".
     *
     * @param  array<string, string|array{0: string, 1?: array<string, mixed>, 2?: array<string, mixed>}>  $nodes
     * @param  list<string>  $edges
     * @return array<string, mixed>
     */
    protected function graph(array $nodes, array $edges = []): array
    {
        $graphNodes = [];
        foreach ($nodes as $id => $spec) {
            [$type, $config, $retry] = array_pad((array) $spec, 3, null);
            $graphNodes[] = array_filter(['id' => $id, 'type' => str_contains($type, '.') ? $type : "test.{$type}", 'name' => $id, 'config' => $config ?? [], 'retry' => $retry], fn ($v) => $v !== null);
        }

        $graphEdges = [];
        foreach ($edges as $index => $edge) {
            [$source, $target] = explode('->', $edge);
            [$sourceNode, $port] = array_pad(explode(':', $source), 2, 'main');
            $graphEdges[] = ['id' => "e{$index}", 'source' => $sourceNode, 'source_port' => $port, 'target' => $target];
        }

        return ['version' => 1, 'nodes' => $graphNodes, 'edges' => $graphEdges];
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    protected function publishedWorkflow(array $graph, ?Workspace $workspace = null): Workflow
    {
        return Workflow::factory()
            ->for($workspace ?? Workspace::factory())
            ->published($graph)
            ->create();
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function runWorkflow(Workflow $workflow, array $input = []): Execution
    {
        return $this->app->make(Engine::class)->start($workflow, $input)->refresh();
    }

    protected function sweep(): void
    {
        $this->app->make(Sweeper::class)->sweep();
    }

    protected function stepFor(Execution $execution, string $nodeId, int $attempt = 1): ExecutionStep
    {
        return $execution->steps()->where('node_id', $nodeId)->where('attempt', $attempt)->sole();
    }

    /**
     * @return list<string>
     */
    protected function nodesRun(Execution $execution): array
    {
        return $execution->steps()->orderBy('id')->pluck('node_id')->all();
    }
}
