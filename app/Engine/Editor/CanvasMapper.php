<?php

namespace App\Engine\Editor;

use App\Engine\Graph\GraphEdge;
use App\Engine\Graph\GraphNode;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\NodeRegistry;
use App\Enums\NodeKind;

/**
 * Translates between the app's graph format and the node and edge arrays the
 * WireFlow canvas draws. The canvas only ever sees display data; config,
 * retries and everything else stay in the saved graph, so the canvas library
 * can be swapped without touching stored workflows.
 */
class CanvasMapper
{
    public const TARGET_HANDLE = 'in';

    public function __construct(protected NodeRegistry $registry) {}

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function toCanvas(WorkflowGraph $graph): array
    {
        return [
            'nodes' => array_values(array_map(fn (GraphNode $node) => $this->node($node), $graph->nodes)),
            'edges' => array_map(fn (GraphEdge $edge) => $this->edge($edge), $graph->edges),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function node(GraphNode $node): array
    {
        $known = $this->registry->has($node->type);
        $definition = $known ? $this->registry->make($node->type) : null;

        return [
            'id' => $node->id,
            'position' => $node->position,
            'data' => [
                'name' => $node->name,
                'type' => $node->type,
                'typeLabel' => $definition?->label() ?? __('Unknown type'),
                'icon' => $definition?->icon() ?? 'question-mark-circle',
                'category' => $definition?->category() ?? '',
                'isTrigger' => $definition?->kind() === NodeKind::Trigger,
                'ports' => $definition ? array_map(fn (string $port) => ['id' => $port, 'label' => $this->portLabel($port)], $definition->ports($node->config)) : [],
                'hasErrorPort' => (bool) $definition?->hasErrorPort(),
                'unknown' => ! $known,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function edge(GraphEdge $edge): array
    {
        return array_filter([
            'id' => $edge->id,
            'source' => $edge->source,
            'sourceHandle' => $edge->sourcePort,
            'target' => $edge->target,
            'targetHandle' => self::TARGET_HANDLE,
            'label' => in_array($edge->sourcePort, ['main'], true) ? null : $this->portLabel($edge->sourcePort),
            'class' => $edge->sourcePort === 'error' ? 'wag-edge-error' : null,
            'animated' => false,
        ], fn ($value) => $value !== null);
    }

    public function portLabel(string $port): string
    {
        return match (true) {
            $port === 'main' => '',
            $port === 'error' => __('error'),
            $port === 'item' => __('each item'),
            str_starts_with($port, 'branch_') => __('branch :n', ['n' => substr($port, 7)]),
            default => str_replace('_', ' ', $port),
        };
    }

    public static function edgeId(string $source, string $port, string $target): string
    {
        return "{$source}__{$port}__{$target}";
    }
}
