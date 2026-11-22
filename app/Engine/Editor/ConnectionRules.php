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
            return __('workflows.editor.that_node_no_longer_exists');
        }

        if ($source === $target) {
            return __('workflows.editor.node_cannot_connect_to_itself');
        }

        $from = $graph->node($source);
        $to = $graph->node($target);

        if (! $this->registry->has($from->type) || ! $this->registry->has($to->type)) {
            return __('workflows.editor.one_of_these_nodes_has');
        }

        $definition = $this->registry->make($from->type);

        if ($port === 'error' ? ! $definition->hasErrorPort() : ! in_array($port, $definition->ports($from->config), true)) {
            return __('workflows.editor.name_has_no_output_named', ['name' => $from->name, 'port' => $port]);
        }

        if ($port === 'error' && ! $from->errorPath) {
            return __('workflows.editor.turn_on_show_on_error_path', ['name' => $from->name]);
        }

        if ($this->registry->make($to->type)->kind() === NodeKind::Trigger) {
            return __('workflows.editor.triggers_start_a_workflow_so');
        }

        if ($graph->hasEdge($source, $port, $target)) {
            return __('workflows.editor.these_nodes_are_already_connected');
        }

        if ($graph->canReach($target, $source)) {
            return __('workflows.editor.that_would_create_a_cycle');
        }

        return null;
    }

    public function connect(WorkflowGraph $graph, string $source, string $port, string $target): WorkflowGraph
    {
        return $graph->withEdge(new GraphEdge(CanvasMapper::edgeId($source, $port, $target), $source, $target, $port));
    }
}
