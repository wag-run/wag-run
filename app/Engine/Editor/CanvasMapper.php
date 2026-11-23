<?php

namespace App\Engine\Editor;

use App\Engine\Graph\GraphEdge;
use App\Engine\Graph\GraphNode;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\NodeRegistry;
use App\Engine\Nodes\Note;
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

    /**
     * How connections are drawn, in the editor and on run pages alike. Each
     * mapped edge carries it, and both canvases pass it as their default for
     * connections drawn in the browser.
     */
    public const EDGE_TYPE = 'bezier';

    public function __construct(protected NodeRegistry $registry) {}

    /**
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function toCanvas(WorkflowGraph $graph): array
    {
        return [
            'nodes' => array_values(array_map(fn (GraphNode $node) => $this->node($node), $graph->nodes)),
            'edges' => array_map(fn (GraphEdge $edge) => $this->edge($edge, $graph->has($edge->source) ? $graph->node($edge->source) : null), $graph->edges),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function node(GraphNode $node): array
    {
        $known = $this->registry->has($node->type);
        $definition = $known ? $this->registry->make($node->type) : null;

        if ($definition?->kind() === NodeKind::Note) {
            return [
                'id' => $node->id,
                'position' => $node->position,
                'connectable' => false,
                'data' => [
                    'isNote' => true,
                    'text' => (string) ($node->config['text'] ?? ''),
                    'html' => Note::html((string) ($node->config['text'] ?? '')),
                    'color' => $color = Note::color($node->config['color'] ?? 'yellow'),
                    'custom' => str_starts_with($color, '#') ? Note::customColors($color) : null,
                    'opacity' => (int) (in_array((string) ($node->config['opacity'] ?? ''), Note::OPACITIES, true) ? $node->config['opacity'] : Note::DEFAULT_OPACITY),
                    'icon' => $definition->icon(),
                    'ports' => [],
                ],
            ];
        }

        return [
            'id' => $node->id,
            'position' => $node->position,
            'data' => [
                'name' => $node->name,
                'type' => $node->type,
                'typeLabel' => $definition?->label() ?? __('workflows.editor.unknown_type'),
                'icon' => $definition?->icon() ?? 'question-mark-circle',
                'category' => $definition?->category() ?? '',
                'isTrigger' => $definition?->kind() === NodeKind::Trigger,
                'ports' => $definition ? array_map(fn (string $port) => ['id' => $port, 'label' => $this->portLabel($port, $node)], $definition->ports($node->config)) : [],
                'hasErrorPort' => $node->errorPath && (bool) $definition?->hasErrorPort(),
                'unknown' => ! $known,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function edge(GraphEdge $edge, ?GraphNode $source = null): array
    {
        return array_filter([
            'id' => $edge->id,
            'source' => $edge->source,
            'sourceHandle' => $edge->sourcePort,
            'target' => $edge->target,
            'targetHandle' => self::TARGET_HANDLE,
            'type' => self::EDGE_TYPE,
            'label' => in_array($edge->sourcePort, ['main'], true) ? null : $this->portLabel($edge->sourcePort, $source),
            'class' => $edge->sourcePort === 'error' ? 'wag-edge-error' : null,
            'animated' => false,
        ], fn ($value) => $value !== null);
    }

    public function portLabel(string $port, ?GraphNode $node = null): string
    {
        if ($node && $port !== 'error' && $this->registry->has($node->type)) {
            $label = $this->registry->make($node->type)->portLabel($port, $node->config);

            if ($label !== null) {
                return $label;
            }
        }

        return match (true) {
            $port === 'main' => '',
            $port === 'error' => __('workflows.editor.error'),
            $port === 'item' => __('workflows.editor.each_item'),
            default => str_replace('_', ' ', $port),
        };
    }

    public static function edgeId(string $source, string $port, string $target): string
    {
        return "{$source}__{$port}__{$target}";
    }
}
