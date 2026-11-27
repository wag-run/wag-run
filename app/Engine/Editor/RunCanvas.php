<?php

namespace App\Engine\Editor;

use App\Engine\Scope;
use App\Enums\StepStatus;
use App\Models\Execution;
use App\Models\ExecutionStep;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;

/**
 * The canvas for one run: the graph of the version the run was pinned to,
 * with each node showing how its latest step ended. A node with no step row
 * was on a path the run never took and shows as "not run". While the run is
 * in progress, a dot travels along each connection into a step that is
 * queued, running or waiting.
 */
class RunCanvas
{
    public const NOT_RUN = 'not_run';

    public function __construct(protected CanvasMapper $mapper) {}

    /**
     * @param  Collection<int, ExecutionStep>  $steps
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function toCanvas(Execution $execution, Collection $steps): array
    {
        $canvas = $this->mapper->toCanvas($execution->version->toGraph());
        $statuses = $this->statuses($steps);
        $active = array_flip($this->activeEdges($execution, $canvas['edges'], $statuses));

        foreach ($canvas['edges'] as $index => $edge) {
            if (isset($active[$edge['id']])) {
                $canvas['edges'][$index]['animated'] = 'dot';
            }
        }

        foreach ($canvas['nodes'] as $index => $node) {
            $canvas['nodes'][$index]['draggable'] = false;

            if ($node['data']['isNote'] ?? false) {
                continue;
            }

            $canvas['nodes'][$index]['data'] = [...$node['data'], ...$this->statusData($statuses[$node['id']] ?? self::NOT_RUN)];
            $canvas['nodes'][$index]['data']['iconHtml'] = Blade::render('<flux:icon :icon="$icon" variant="mini" class="size-4" />', ['icon' => $node['data']['icon']]);
            $canvas['nodes'][$index]['connectable'] = false;
        }

        return $canvas;
    }

    /**
     * The connections the run is on now: into a step that is queued,
     * running or waiting, from a node that has run. None once the run ends.
     *
     * @param  list<array<string, mixed>>  $edges  canvas edges
     * @param  array<string, string>  $statuses  from statuses()
     * @return list<string> edge ids
     */
    public function activeEdges(Execution $execution, array $edges, array $statuses): array
    {
        if (! $execution->isInProgress()) {
            return [];
        }

        $moving = [StepStatus::Pending->value, StepStatus::Running->value, StepStatus::Waiting->value];

        return array_values(array_map(
            fn (array $edge) => $edge['id'],
            array_filter($edges, fn (array $edge) => in_array($statuses[$edge['target']] ?? null, $moving, true) && isset($statuses[$edge['source']])),
        ));
    }

    /**
     * Each node's status: that of its latest step row, else "not run".
     *
     * @param  Collection<int, ExecutionStep>  $steps
     * @return array<string, string>
     */
    public function statuses(Collection $steps): array
    {
        return $steps->sortBy('id')
            ->mapWithKeys(fn (ExecutionStep $step) => [$step->node_id => $step->status->value])
            ->all();
    }

    /**
     * @return array{status: string, statusLabel: string}
     */
    public function statusData(string $status): array
    {
        return [
            'status' => $status,
            'statusLabel' => $status === self::NOT_RUN ? __('common.not_run') : StepStatus::from($status)->label(),
        ];
    }

    /**
     * "Iteration 3", or "Iteration 2 › 3" inside nested loops, for a step's
     * whole scope. A loop that counts passes, such as a While loop, reads
     * "Pass 3", so a While loop inside a For loop reads "Iteration 2 › Pass 3".
     *
     * @param  list<int>  $passLoops  ids of the steps of loops that count passes
     */
    public static function scopeLabel(string $scope, array $passLoops = []): ?string
    {
        if ($scope === '') {
            return null;
        }

        $parts = [];
        $previous = null;

        foreach (explode('/', $scope) as $frame) {
            [$loopStepId, $index] = array_pad(explode('.', substr($frame, 1), 2), 2, '0');
            $key = in_array((int) $loopStepId, $passLoops, true) ? 'workflows.editor.pass_n' : 'workflows.editor.iteration_n';
            $parts[] = $key === $previous ? (string) ((int) $index + 1) : __($key, ['n' => (int) $index + 1]);
            $previous = $key;
        }

        return implode(' › ', $parts);
    }

    /**
     * "Iteration 3" or "Pass 3": where a step is in the innermost loop
     * around it.
     *
     * @param  list<int>  $passLoops  ids of the steps of loops that count passes
     */
    public static function iterationLabel(ExecutionStep $step, array $passLoops = []): ?string
    {
        $frame = Scope::innermost($step->scope_key);

        if ($frame === null) {
            return null;
        }

        return __(in_array($frame['loop_step_id'], $passLoops, true) ? 'workflows.editor.pass_n' : 'workflows.editor.iteration_n', ['n' => $frame['index'] + 1]);
    }
}
