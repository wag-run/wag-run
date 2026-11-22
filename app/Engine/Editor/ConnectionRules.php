<?php

namespace App\Engine\Editor;

use App\Engine\Graph\GraphEdge;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\NodeRegistry;
use App\Enums\NodeKind;

/**
 * Decides whether the editor may draw a connection, with a reason the user
 * sees when it may not.
 */
class ConnectionRules
{
    public function __construct(protected NodeRegistry $registry) {}

    public function check(WorkflowGraph $graph, string $source, string $port, string $target): ?string
    {
        if (! $graph->has($source) || ! $graph->has($target)) {
            return __('That node no longer exists. Reload the editor.');
        }

        if ($source === $target) {
            return __('A node cannot connect to itself.');
        }

        $from = $graph->node($source);
        $to = $graph->node($target);

        if (! $this->registry->has($from->type) || ! $this->registry->has($to->type)) {
            return __('One of these nodes has an unknown type.');
        }

        $definition = $this->registry->make($from->type);

        if ($port === 'error' ? ! $definition->hasErrorPort() : ! in_array($port, $definition->ports($from->config), true)) {
            return __(':name has no output named ":port".', ['name' => $from->name, 'port' => $port]);
        }

        if ($this->registry->make($to->type)->kind() === NodeKind::Trigger) {
            return __('Triggers start a workflow, so nothing can connect into them.');
        }

        if ($graph->hasEdge($source, $port, $target)) {
            return __('These nodes are already connected.');
        }

        if ($graph->canReach($target, $source)) {
            return __('That would create a cycle. Use a For loop node to repeat steps.');
        }

        return null;
    }

    public function connect(WorkflowGraph $graph, string $source, string $port, string $target): WorkflowGraph
    {
        return $graph->withEdge(new GraphEdge(CanvasMapper::edgeId($source, $port, $target), $source, $target, $port));
    }
}
