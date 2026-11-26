<?php

namespace App\Engine\Editor;

use App\Engine\Definitions\Output;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\NodeRegistry;
use App\Engine\Nodes\Actions\ForLoop;
use App\Enums\NodeKind;
use App\Models\Workspace;

/**
 * Lists the values a node can reference: its trigger's outputs, outputs of
 * every node that runs before it (one no trigger leads to never runs, so it
 * is left out), the current loop item, the error it was
 * reached from, and the workspace's variables and secrets. Built from each
 * node's declared outputs, so custom nodes appear the same way.
 */
class DataPicker
{
    public function __construct(protected NodeRegistry $registry) {}

    /**
     * @return list<array{label: string, items: list<array{label: string, path: string, type: string}>}>
     */
    public function optionsFor(WorkflowGraph $graph, string $nodeId, Workspace $workspace): array
    {
        return array_map(fn (array $group) => [
            'label' => (string) $group['label'],
            'items' => array_values(array_map(fn (array $item) => [...$item, 'label' => (string) $item['label']], $group['items'])),
        ], $this->groups($graph, $nodeId, $workspace));
    }

    /**
     * @return list<array{label: mixed, items: array<array{label: mixed, path: string, type: string}>}>
     */
    protected function groups(WorkflowGraph $graph, string $nodeId, Workspace $workspace): array
    {
        if (! $graph->has($nodeId)) {
            return [];
        }

        $ancestors = $graph->ancestorsOf($nodeId);
        $reached = $this->reachedFromTriggers($graph);
        $loopBodies = $this->loopBodies($graph);
        $distances = $this->distancesTo($graph, $nodeId);
        $ancestorGroups = [];

        foreach ($graph->nodes as $node) {
            if (! isset($ancestors[$node->id]) || ! $this->registry->has($node->type)) {
                continue;
            }

            $definition = $this->registry->make($node->type);

            if ($definition->kind() === NodeKind::Trigger) {
                $items = $this->flatten($definition->outputs($node->config), 'trigger');

                if ($items !== []) {
                    $ancestorGroups[] = ['distance' => $distances[$node->id] ?? PHP_INT_MAX, 'group' => ['label' => __('workflows.editor.trigger_name', ['name' => $node->name]), 'items' => $items]];
                }

                continue;
            }

            if (! isset($reached[$node->id]) || $this->hiddenByLoop($node->id, $nodeId, $loopBodies)) {
                continue;
            }

            $items = $this->flatten($definition->outputs($node->config), "nodes.{$node->id}.output");

            if ($items !== []) {
                $ancestorGroups[] = ['distance' => $distances[$node->id] ?? PHP_INT_MAX, 'group' => ['label' => $node->name, 'items' => $items]];
            }
        }

        usort($ancestorGroups, fn (array $a, array $b) => $a['distance'] <=> $b['distance']);
        $groups = array_column($ancestorGroups, 'group');

        foreach ($loopBodies as $loopId => $body) {
            if (isset($body[$nodeId])) {
                $groups[] = ['label' => __('workflows.editor.loop_name', ['name' => $graph->node($loopId)->name]), 'items' => [
                    ['label' => __('workflows.editor.current_item'), 'path' => 'item', 'type' => 'any'],
                    ['label' => __('workflows.editor.item_index'), 'path' => 'loop.index', 'type' => 'number'],
                    ['label' => __('workflows.editor.item_count'), 'path' => 'loop.count', 'type' => 'number'],
                ]];
            }
        }

        foreach ($graph->incoming($nodeId) as $edge) {
            if ($edge->sourcePort === 'error') {
                $groups[] = ['label' => __('common.error'), 'items' => [
                    ['label' => __('workflows.editor.error_message'), 'path' => 'error.message', 'type' => 'string'],
                    ['label' => __('workflows.editor.attempt'), 'path' => 'error.attempt', 'type' => 'number'],
                ]];

                break;
            }
        }

        $variables = $workspace->variables()->orderBy('key')->pluck('key')->map(fn (string $key) => ['label' => $key, 'path' => "vars.{$key}", 'type' => 'string'])->values()->all();
        $secrets = $workspace->secrets()->orderBy('key')->pluck('key')->map(fn (string $key) => ['label' => $key, 'path' => "secrets.{$key}", 'type' => 'string'])->values()->all();

        if ($variables !== []) {
            $groups[] = ['label' => __('common.variables'), 'items' => $variables];
        }

        if ($secrets !== []) {
            $groups[] = ['label' => __('common.secrets'), 'items' => $secrets];
        }

        $groups[] = ['label' => __('workflows.editor.execution'), 'items' => [
            ['label' => __('common.execution_id'), 'path' => 'execution.id', 'type' => 'number'],
            ['label' => __('workflows.editor.workflow_name'), 'path' => 'execution.workflow', 'type' => 'string'],
            ['label' => __('workflows.editor.started_at'), 'path' => 'execution.started_at', 'type' => 'string'],
            ['label' => __('workflows.editor.trigger_node_id'), 'path' => 'execution.trigger.id', 'type' => 'string'],
            ['label' => __('workflows.editor.trigger_node_type'), 'path' => 'execution.trigger.type', 'type' => 'string'],
            ['label' => __('workflows.editor.trigger_settings'), 'path' => 'execution.trigger.config', 'type' => 'object'],
        ]];

        return $groups;
    }

    /**
     * How many connections each node is from $nodeId, walking backwards, so
     * the closest predecessor can be listed first.
     *
     * @return array<string, int>
     */
    protected function distancesTo(WorkflowGraph $graph, string $nodeId): array
    {
        $distances = [$nodeId => 0];
        $queue = [$nodeId];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($graph->incoming($current) as $edge) {
                if (! isset($distances[$edge->source])) {
                    $distances[$edge->source] = $distances[$current] + 1;
                    $queue[] = $edge->source;
                }
            }
        }

        return $distances;
    }

    /**
     * @param  list<Output>  $outputs
     * @return list<array{label: string, path: string, type: string}>
     */
    protected function flatten(array $outputs, string $prefix, string $labelPrefix = ''): array
    {
        $items = [];

        foreach ($outputs as $output) {
            $path = "{$prefix}.{$output->name}";
            $label = $labelPrefix === '' ? $output->label : "{$labelPrefix} › {$output->label}";
            $items[] = ['label' => $label, 'path' => $path, 'type' => $output->type];

            if ($output->type === 'object' && $output->children !== []) {
                array_push($items, ...$this->flatten($output->children, $path, $label));
            }

            if ($output->type === 'file') {
                array_push($items, ...$this->flatten([
                    new Output('name', __('run_files.name'), 'string'),
                    new Output('mime', __('run_files.type'), 'string'),
                    new Output('size', __('run_files.size_in_bytes'), 'number'),
                ], $path, $label));
            }
        }

        return $items;
    }

    /**
     * Every node a run can get to: the ones some trigger leads to.
     *
     * @return array<string, true>
     */
    protected function reachedFromTriggers(WorkflowGraph $graph): array
    {
        $reached = [];

        foreach ($graph->nodes as $node) {
            if ($this->registry->has($node->type) && $this->registry->make($node->type)->kind() === NodeKind::Trigger) {
                $reached += $graph->reachableFrom($node->id);
            }
        }

        return $reached;
    }

    /**
     * Nodes inside each For loop's body, keyed by the loop's node id.
     *
     * @return array<string, array<string, true>>
     */
    protected function loopBodies(WorkflowGraph $graph): array
    {
        $bodies = [];

        foreach ($graph->nodes as $node) {
            if ($node->type === ForLoop::type()) {
                $bodies[$node->id] = $graph->reachableFrom($node->id, 'item');
            }
        }

        return $bodies;
    }

    /**
     * Steps inside a loop body cannot be read from outside that loop.
     *
     * @param  array<string, array<string, true>>  $loopBodies
     */
    protected function hiddenByLoop(string $ancestorId, string $nodeId, array $loopBodies): bool
    {
        foreach ($loopBodies as $body) {
            if (isset($body[$ancestorId]) && ! isset($body[$nodeId])) {
                return true;
            }
        }

        return false;
    }
}
