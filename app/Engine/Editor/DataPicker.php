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
 * every node that runs before it, the current loop item, the error it was
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
        $loopBodies = $this->loopBodies($graph);
        $groups = [];

        foreach ($graph->nodes as $node) {
            if (! isset($ancestors[$node->id]) || ! $this->registry->has($node->type)) {
                continue;
            }

            $definition = $this->registry->make($node->type);

            if ($definition->kind() === NodeKind::Trigger) {
                $items = $this->flatten($definition->outputs($node->config), 'trigger');

                if ($items !== []) {
                    $groups[] = ['label' => __('Trigger: :name', ['name' => $node->name]), 'items' => $items];
                }

                continue;
            }

            if ($this->hiddenByLoop($node->id, $nodeId, $loopBodies)) {
                continue;
            }

            $items = $this->flatten($definition->outputs($node->config), "nodes.{$node->id}.output");

            if ($items !== []) {
                $groups[] = ['label' => $node->name, 'items' => $items];
            }
        }

        foreach ($loopBodies as $loopId => $body) {
            if (isset($body[$nodeId])) {
                $groups[] = ['label' => __('Loop: :name', ['name' => $graph->node($loopId)->name]), 'items' => [
                    ['label' => __('Current item'), 'path' => 'item', 'type' => 'any'],
                    ['label' => __('Item index'), 'path' => 'loop.index', 'type' => 'number'],
                    ['label' => __('Item count'), 'path' => 'loop.count', 'type' => 'number'],
                ]];
            }
        }

        foreach ($graph->incoming($nodeId) as $edge) {
            if ($edge->sourcePort === 'error') {
                $groups[] = ['label' => __('Error'), 'items' => [
                    ['label' => __('Error message'), 'path' => 'error.message', 'type' => 'string'],
                    ['label' => __('Attempt'), 'path' => 'error.attempt', 'type' => 'number'],
                ]];

                break;
            }
        }

        $variables = $workspace->variables()->orderBy('key')->pluck('key')->map(fn (string $key) => ['label' => $key, 'path' => "vars.{$key}", 'type' => 'string'])->values()->all();
        $secrets = $workspace->secrets()->orderBy('key')->pluck('key')->map(fn (string $key) => ['label' => $key, 'path' => "secrets.{$key}", 'type' => 'string'])->values()->all();

        if ($variables !== []) {
            $groups[] = ['label' => __('Variables'), 'items' => $variables];
        }

        if ($secrets !== []) {
            $groups[] = ['label' => __('Secrets'), 'items' => $secrets];
        }

        $groups[] = ['label' => __('Execution'), 'items' => [
            ['label' => __('Execution id'), 'path' => 'execution.id', 'type' => 'number'],
            ['label' => __('Workflow name'), 'path' => 'execution.workflow', 'type' => 'string'],
            ['label' => __('Started at'), 'path' => 'execution.started_at', 'type' => 'string'],
        ]];

        return $groups;
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
        }

        return $items;
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
