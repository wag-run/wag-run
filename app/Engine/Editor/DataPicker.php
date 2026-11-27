<?php

namespace App\Engine\Editor;

use App\Engine\Definitions\Output;
use App\Engine\Graph\GraphNode;
use App\Engine\Graph\GraphVariable;
use App\Engine\Graph\WorkflowGraph;
use App\Engine\NodeRegistry;
use App\Engine\Nodes\Actions\ForLoop;
use App\Engine\Nodes\Actions\Merge;
use App\Engine\Nodes\Actions\WhileLoop;
use App\Enums\NodeKind;
use App\Models\StoreEntry;
use App\Models\Workspace;

/**
 * Lists the values a node can reference: its trigger's outputs, outputs of
 * every node that runs before it (one no trigger leads to never runs, so it
 * is left out), the current loop item, the error it was reached from, the
 * workflow's variables, and the workspace's data store entries and
 * secrets. Built from each node's declared outputs, so custom nodes appear
 * the same way. A loop and a Merge also offer the outputs of the nodes
 * they gathered. Inside a While loop, and in its own condition, the pass
 * before and every earlier pass are offered through loop.previous and
 * loop.results.
 */
class DataPicker
{
    public function __construct(protected NodeRegistry $registry) {}

    /**
     * @return list<array{label: string, items: list<array{label: string, path: string, type: string}>}>
     */
    public function optionsFor(WorkflowGraph $graph, string $nodeId, Workspace $workspace): array
    {
        return $this->withTextLabels($this->groups($graph, $nodeId, $workspace));
    }

    /**
     * What a workflow variable's starting value can reference. It is worked
     * out when a run starts, before any node has run, so that is each
     * trigger's outputs, the data store, and the run itself.
     *
     * @return list<array{label: string, items: list<array{label: string, path: string, type: string}>}>
     */
    public function optionsForStartingValue(WorkflowGraph $graph, Workspace $workspace): array
    {
        $groups = [];

        foreach ($graph->nodes as $node) {
            $definition = $this->registry->has($node->type) ? $this->registry->make($node->type) : null;
            $items = $definition?->kind() === NodeKind::Trigger ? $this->flatten($definition->outputs($node->config), 'trigger') : [];

            if ($items !== []) {
                $groups[] = ['label' => __('workflows.editor.trigger_name', ['name' => $node->name]), 'items' => $items];
            }
        }

        $entries = $this->storeEntries($workspace);

        if ($entries !== []) {
            $groups[] = ['label' => __('common.data_store'), 'items' => $entries];
        }

        $groups[] = $this->executionGroup();

        return $this->withTextLabels($groups);
    }

    /**
     * @param  list<array{label: mixed, items: array<array{label: mixed, path: string, type: string}>}>  $groups
     * @return list<array{label: string, items: list<array{label: string, path: string, type: string}>}>
     */
    protected function withTextLabels(array $groups): array
    {
        return array_map(fn (array $group) => [
            'label' => (string) $group['label'],
            'items' => array_values(array_map(fn (array $item) => [...$item, 'label' => (string) $item['label']], $group['items'])),
        ], $groups);
    }

    /**
     * @return list<array{label: string, path: string, type: string}>
     */
    protected function storeEntries(Workspace $workspace): array
    {
        return array_values($workspace->storeEntries()->orderBy('key')->get()
            ->map(fn (StoreEntry $entry) => ['label' => $entry->key, 'path' => "store.{$entry->key}", 'type' => $entry->is_collection ? 'object' : $entry->type->pickerType()])->all());
    }

    /**
     * @return array{label: mixed, items: list<array{label: mixed, path: string, type: string}>}
     */
    protected function executionGroup(): array
    {
        return ['label' => __('workflows.editor.execution'), 'items' => [
            ['label' => __('common.execution_id'), 'path' => 'execution.id', 'type' => 'number'],
            ['label' => __('workflows.editor.workflow_name'), 'path' => 'execution.workflow', 'type' => 'string'],
            ['label' => __('workflows.editor.started_at'), 'path' => 'execution.started_at', 'type' => 'string'],
            ['label' => __('workflows.editor.trigger_node_id'), 'path' => 'execution.trigger.id', 'type' => 'string'],
            ['label' => __('workflows.editor.trigger_node_type'), 'path' => 'execution.trigger.type', 'type' => 'string'],
            ['label' => __('workflows.editor.trigger_settings'), 'path' => 'execution.trigger.config', 'type' => 'object'],
        ]];
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

            $items = [
                ...$this->flatten($definition->outputs($node->config), "nodes.{$node->id}.output"),
                ...$this->gathered($graph, $node, $loopBodies),
            ];

            if ($items !== []) {
                $ancestorGroups[] = ['distance' => $distances[$node->id] ?? PHP_INT_MAX, 'group' => ['label' => $node->name, 'items' => $items]];
            }
        }

        foreach ($this->loopGroups($graph, $nodeId, $loopBodies) as $loopId => $group) {
            $ancestorGroups[] = ['distance' => $distances[$loopId] ?? PHP_INT_MAX, 'group' => $group];
        }

        array_push($ancestorGroups, ...array_map(fn (array $group) => ['distance' => 0, 'group' => $group], $this->ownPassGroups($graph, $nodeId, $loopBodies)));

        usort($ancestorGroups, fn (array $a, array $b) => $a['distance'] <=> $b['distance']);
        $groups = array_column($ancestorGroups, 'group');

        foreach ($graph->incoming($nodeId) as $edge) {
            if ($edge->sourcePort === 'error') {
                $groups[] = ['label' => __('common.error'), 'items' => [
                    ['label' => __('workflows.editor.error_message'), 'path' => 'error.message', 'type' => 'string'],
                    ['label' => __('workflows.editor.attempt'), 'path' => 'error.attempt', 'type' => 'number'],
                ]];

                break;
            }
        }

        $entries = $this->storeEntries($workspace);
        $secrets = $workspace->secrets()->orderBy('key')->pluck('key')->map(fn (string $key) => ['label' => $key, 'path' => "secrets.{$key}", 'type' => 'string'])->values()->all();

        $variables = array_values(array_map(
            fn (GraphVariable $variable) => ['label' => $variable->name, 'path' => "vars.{$variable->name}", 'type' => $variable->valueType()?->pickerType() ?? 'any'],
            $graph->variables,
        ));

        if ($variables !== []) {
            $groups[] = ['label' => __('workflows.edit.variables'), 'items' => $variables];
        }

        if ($entries !== []) {
            $groups[] = ['label' => __('common.data_store'), 'items' => $entries];
        }

        if ($secrets !== []) {
            $groups[] = ['label' => __('common.secrets'), 'items' => $secrets];
        }

        $groups[] = $this->executionGroup();

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
     * What a node reads about the loops it runs inside. loop describes the
     * innermost loop only, and item is the current item of the nearest For
     * loop, so each is offered once, under the loop it belongs to.
     *
     * @param  array<string, array<string, true>>  $loopBodies
     * @return array<string, array{label: mixed, items: list<array{label: mixed, path: string, type: string}>}> by loop node id
     */
    protected function loopGroups(WorkflowGraph $graph, string $nodeId, array $loopBodies): array
    {
        $around = array_filter($loopBodies, fn (array $body) => isset($body[$nodeId]));
        uasort($around, fn (array $a, array $b) => count($a) <=> count($b));
        $innermost = array_key_first($around);
        $groups = [];

        foreach (array_keys($around) as $loopId) {
            $loop = $graph->node($loopId);
            $isWhile = $loop->type === WhileLoop::type();

            if ($isWhile && $loopId !== $innermost) {
                continue;
            }

            $groups[$loopId] = ['label' => __('workflows.editor.loop_name', ['name' => $loop->name]), 'items' => match (true) {
                $isWhile => $this->passItems($graph, $loopId, $loopBodies),
                $loopId === $innermost => [
                    ['label' => __('workflows.editor.current_item'), 'path' => 'item', 'type' => 'any'],
                    ['label' => __('workflows.editor.item_index'), 'path' => 'loop.index', 'type' => 'number'],
                    ['label' => __('workflows.editor.item_count'), 'path' => 'loop.count', 'type' => 'number'],
                ],
                default => [['label' => __('workflows.editor.current_item'), 'path' => 'item', 'type' => 'any']],
            }];

            if (! $isWhile) {
                break;
            }
        }

        return $groups;
    }

    /**
     * What a While loop's own condition reads when it is checked after a
     * pass: the nodes of that pass, as a step inside it reads them, and the
     * passes before it.
     *
     * @param  array<string, array<string, true>>  $loopBodies
     * @return list<array{label: mixed, items: list<array{label: mixed, path: string, type: string}>}>
     */
    protected function ownPassGroups(WorkflowGraph $graph, string $nodeId, array $loopBodies): array
    {
        if (! isset($loopBodies[$nodeId]) || $graph->node($nodeId)->type !== WhileLoop::type()) {
            return [];
        }

        $groups = [];

        foreach ($this->directBody($nodeId, $loopBodies) as $id) {
            $items = $this->outputsOf($graph->node($id), "nodes.{$id}.output");

            if ($items !== []) {
                $groups[] = ['label' => __('workflows.editor.pass_just_finished_name', ['name' => $graph->node($id)->name]), 'items' => $items];
            }
        }

        $groups[] = ['label' => __('workflows.editor.loop_name', ['name' => $graph->node($nodeId)->name]), 'items' => $this->passItems($graph, $nodeId, $loopBodies)];

        return $groups;
    }

    /**
     * What a step inside a While loop's pass reads about the loop: the
     * pass's index, each node of the pass before, and every earlier pass.
     *
     * @param  array<string, array<string, true>>  $loopBodies
     * @return list<array{label: mixed, path: string, type: string}>
     */
    protected function passItems(WorkflowGraph $graph, string $loopId, array $loopBodies): array
    {
        $items = [['label' => __('workflows.editor.pass_index'), 'path' => 'loop.index', 'type' => 'number']];

        foreach ($this->directBody($loopId, $loopBodies) as $id) {
            array_push($items, ...$this->outputsOf($graph->node($id), "loop.previous.{$id}.output", __('workflows.editor.previous_pass_name', ['name' => $graph->node($id)->name])));
        }

        $items[] = ['label' => __('workflows.editor.earlier_passes'), 'path' => 'loop.results', 'type' => 'array'];

        return $items;
    }

    /**
     * Paths into what a loop or a Merge gathered, each node in the same
     * shape as nodes.<id>: every node of a loop's first iteration (and of
     * a While loop's last pass), and the last node of each branch.
     *
     * @param  array<string, array<string, true>>  $loopBodies
     * @return list<array{label: string, path: string, type: string}>
     */
    protected function gathered(WorkflowGraph $graph, GraphNode $node, array $loopBodies): array
    {
        $sources = [];

        if (isset($loopBodies[$node->id])) {
            $isWhile = $node->type === WhileLoop::type();

            foreach ($this->directBody($node->id, $loopBodies) as $id) {
                $name = $graph->node($id)->name;

                if ($isWhile) {
                    $sources[] = [$id, "last.{$id}", __('workflows.editor.last_pass_name', ['name' => $name])];
                }

                $sources[] = [$id, "results[0].{$id}", __($isWhile ? 'workflows.editor.first_pass_name' : 'workflows.editor.first_iteration_name', ['name' => $name])];
            }
        } elseif ($node->type === Merge::type()) {
            foreach ($graph->incoming($node->id) as $edge) {
                $sources[] = [$edge->source, "branches.{$edge->source}", __('nodes.merge.branch_outputs').' › '.$graph->node($edge->source)->name];
            }
        }

        $items = [];

        foreach ($sources as [$id, $path, $label]) {
            array_push($items, ...$this->outputsOf($graph->node($id), "nodes.{$node->id}.output.{$path}.output", $label));
        }

        return $items;
    }

    /**
     * A node's declared outputs as paths under $prefix, or none when its
     * type is not installed.
     *
     * @return list<array{label: string, path: string, type: string}>
     */
    protected function outputsOf(GraphNode $node, string $prefix, string $labelPrefix = ''): array
    {
        return $this->registry->has($node->type)
            ? $this->flatten($this->registry->make($node->type)->outputs($node->config), $prefix, $labelPrefix)
            : [];
    }

    /**
     * The nodes of a loop's body that run in its own iterations, not in
     * those of a loop nested inside it.
     *
     * @param  array<string, array<string, true>>  $loopBodies
     * @return list<string>
     */
    protected function directBody(string $loopId, array $loopBodies): array
    {
        $nested = [];

        foreach (array_intersect_key($loopBodies, $loopBodies[$loopId]) as $body) {
            $nested += $body;
        }

        return array_keys(array_diff_key($loopBodies[$loopId], $nested));
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
     * Nodes inside each loop's body, keyed by the loop's node id: what a
     * For loop runs for each item, and a While loop on each pass.
     *
     * @return array<string, array<string, true>>
     */
    protected function loopBodies(WorkflowGraph $graph): array
    {
        $bodies = [];

        foreach ($graph->nodes as $node) {
            $port = match ($node->type) {
                ForLoop::type() => 'item',
                WhileLoop::type() => 'pass',
                default => null,
            };

            if ($port !== null) {
                $bodies[$node->id] = $graph->reachableFrom($node->id, $port);
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
