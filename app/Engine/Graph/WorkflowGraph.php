<?php

namespace App\Engine\Graph;

use InvalidArgumentException;

/**
 * A workflow graph in the app's own JSON format, independent of the canvas
 * library that edits it:
 *
 *     {
 *       "version": 1,
 *       "nodes": [{"id": "n1", "type": "core.manual_trigger", "name": "Start",
 *                  "config": {}, "retry": {"max_retries": 2}, "position": {"x": 0, "y": 0}}],
 *       "edges": [{"id": "e1", "source": "n1", "source_port": "main", "target": "n2"}]
 *     }
 */
final readonly class WorkflowGraph
{
    public const FORMAT_VERSION = 1;

    /**
     * @param  array<string, GraphNode>  $nodes  keyed by node id
     * @param  list<GraphEdge>  $edges
     */
    public function __construct(
        public array $nodes = [],
        public array $edges = [],
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        $nodes = [];
        foreach ($data['nodes'] ?? [] as $node) {
            if (is_array($node)) {
                $graphNode = GraphNode::fromArray($node);
                $nodes[$graphNode->id] = $graphNode;
            }
        }

        $edges = [];
        foreach ($data['edges'] ?? [] as $edge) {
            if (is_array($edge)) {
                $edges[] = GraphEdge::fromArray($edge);
            }
        }

        return new self($nodes, $edges);
    }

    public static function empty(): self
    {
        return new self;
    }

    /**
     * @return array{version: int, nodes: list<array<string, mixed>>, edges: list<array<string, string>>}
     */
    public function toArray(): array
    {
        return [
            'version' => self::FORMAT_VERSION,
            'nodes' => array_values(array_map(fn (GraphNode $node) => $node->toArray(), $this->nodes)),
            'edges' => array_map(fn (GraphEdge $edge) => $edge->toArray(), $this->edges),
        ];
    }

    public function has(string $nodeId): bool
    {
        return isset($this->nodes[$nodeId]);
    }

    public function node(string $nodeId): GraphNode
    {
        return $this->nodes[$nodeId] ?? throw new InvalidArgumentException("The graph has no node [{$nodeId}].");
    }

    /**
     * @return list<GraphEdge>
     */
    public function outgoing(string $nodeId, ?string $port = null): array
    {
        return array_values(array_filter(
            $this->edges,
            fn (GraphEdge $edge) => $edge->source === $nodeId && ($port === null || $edge->sourcePort === $port),
        ));
    }

    /**
     * @return list<GraphEdge>
     */
    public function incoming(string $nodeId): array
    {
        return array_values(array_filter($this->edges, fn (GraphEdge $edge) => $edge->target === $nodeId));
    }

    /**
     * Every node reachable from $nodeId by following edges, $nodeId excluded.
     *
     * @return array<string, true>
     */
    public function reachableFrom(string $nodeId, ?string $port = null): array
    {
        $seen = [];
        $queue = array_map(fn (GraphEdge $edge) => $edge->target, $this->outgoing($nodeId, $port));

        while ($queue !== []) {
            $current = array_shift($queue);

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;

            foreach ($this->outgoing($current) as $edge) {
                $queue[] = $edge->target;
            }
        }

        return $seen;
    }

    public function canReach(string $from, string $to): bool
    {
        return isset($this->reachableFrom($from)[$to]);
    }

    /**
     * Whether the graph contains a cycle. Repetition is the For loop node's
     * job, so cycles are not allowed.
     */
    public function hasCycle(): bool
    {
        foreach (array_keys($this->nodes) as $nodeId) {
            if ($this->canReach($nodeId, $nodeId)) {
                return true;
            }
        }

        return false;
    }
}
