<?php

namespace App\Engine\Editor;

use App\Engine\Graph\GraphEdge;
use App\Engine\Graph\GraphNode;
use App\Engine\Graph\GraphValidator;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\NodeRegistry;
use App\Enums\NodeKind;

/**
 * Changes a node's id in the editor, keeping the draft working: its
 * connections follow it, and every placeholder or expression that reads
 * nodes.<old id> reads nodes.<new id> instead.
 *
 * Only actions can be renamed. A trigger's id keys its published webhook,
 * form, schedule, or subscription, so changing it would change its URL or
 * lose its state.
 */
class NodeRenamer
{
    public function __construct(protected NodeRegistry $registry) {}

    /**
     * Why $from cannot become $to, or null when it can.
     */
    public function check(WorkflowGraph $graph, string $from, string $to): ?string
    {
        if (! $graph->has($from)) {
            return __('workflows.editor.that_node_no_longer_exists');
        }

        $type = $graph->node($from)->type;

        if (! $this->registry->has($type) || $this->registry->make($type)->kind() !== NodeKind::Action) {
            return __('workflows.editor.only_actions_can_change_their_id');
        }

        if (! preg_match(GraphValidator::NODE_ID_PATTERN, $to)) {
            return __('workflows.editor.node_id_must_start_with');
        }

        if ($to !== $from && $graph->has($to)) {
            return __('workflows.editor.another_node_already_uses_id', ['id' => $to]);
        }

        return null;
    }

    /**
     * The graph with node $from renamed to $to. Call check() first.
     */
    public function rename(WorkflowGraph $graph, string $from, string $to): WorkflowGraph
    {
        if ($from === $to) {
            return $graph;
        }

        $nodes = [];

        foreach ($graph->nodes as $id => $node) {
            $id = $id === $from ? $to : $id;
            $nodes[$id] = new GraphNode(
                $id,
                $node->type,
                $node->name,
                self::rewriteReferences($node->config, $from, $to),
                $node->retry,
                $node->position,
                $node->errorPath,
                $node->waitForAll,
            );
        }

        $edges = array_map(function (GraphEdge $edge) use ($from, $to) {
            if ($edge->source !== $from && $edge->target !== $from) {
                return $edge;
            }

            $source = $edge->source === $from ? $to : $edge->source;
            $target = $edge->target === $from ? $to : $edge->target;
            $id = $edge->id === CanvasMapper::edgeId($edge->source, $edge->sourcePort, $edge->target)
                ? CanvasMapper::edgeId($source, $edge->sourcePort, $target)
                : $edge->id;

            return new GraphEdge($id, $source, $target, $edge->sourcePort);
        }, $graph->edges);

        return $graph->with($nodes, $edges);
    }

    /**
     * Point references to node $from at node $to, in a config value and
     * everything nested in it. Matches nodes.from, nodes?.from, and
     * nodes['from'], and nodes["from"], and leaves longer ids such as
     * nodes.from_2 and keys such as item.nodes.from alone.
     */
    public static function rewriteReferences(mixed $value, string $from, string $to): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item) => self::rewriteReferences($item, $from, $to), $value);
        }

        if (! is_string($value) || ! str_contains($value, $from)) {
            return $value;
        }

        $id = preg_quote($from, '/');

        $value = preg_replace_callback(
            '/(?<![\w.])nodes(\??\.)'.$id.'(?!\w)/',
            fn (array $match) => "nodes{$match[1]}{$to}",
            $value,
        ) ?? $value;

        return preg_replace_callback(
            '/(?<![\w.])nodes(\s*\[\s*)([\'"])'.$id.'\2/',
            fn (array $match) => "nodes{$match[1]}{$match[2]}{$to}{$match[2]}",
            $value,
        ) ?? $value;
    }
}
