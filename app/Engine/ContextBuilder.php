<?php

namespace App\Engine;

use App\Enums\StepStatus;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Services\DataStore;

/**
 * Builds the data a step can read: the trigger input, outputs of earlier
 * nodes it can see, workspace values, and the current loop item.
 *
 *     trigger            the execution's input
 *     nodes.<id>.output  the latest output of that node visible from this step;
 *                        a finished loop's includes its results (LoopResults)
 *     vars.<name>        the run's workflow variables, read when used (see VariableData)
 *     store.<key>        the workspace's data store, read when used (see StoreData)
 *     secrets.<key>      workspace secrets (in memory only; masked in logs)
 *     execution          id, workflow, trigger type, started_at, and
 *                        trigger: the id, node type, and settings of the
 *                        trigger node that started the run
 *     item, loop         inside a For loop: the current item, and loop.index
 *                        and loop.count. Inside a While loop: loop.index
 *                        and loop.previous (the pass before, each node as
 *                        in nodes.<id>)
 *     error              for steps reached through an error output
 */
class ContextBuilder
{
    public function __construct(protected DataStore $store) {}

    /**
     * @param  string|null  $scope  read the run's data from this scope instead of the step's own, as a loop does when it looks at the pass it just finished
     * @param  bool  $beforeFirstPass  for a loop that repeats, before it has run a pass: loop is its own, with no pass in it yet
     * @return array<string, mixed>
     */
    public function build(ExecutionStep $step, ?string $scope = null, bool $beforeFirstPass = false): array
    {
        $execution = $step->execution;
        $scope ??= $step->scope_key;

        $visible = $execution->steps()
            ->where('status', StepStatus::Complete)
            ->whereIn('scope_key', Scope::visibleFrom($scope))
            ->orderBy('id')
            ->get(['id', 'node_id', 'scope_key', 'status', 'wait_type', 'output', 'output_ports']);

        $results = LoopResults::for($execution);

        $data = [
            'trigger' => $execution->input ?? [],
            'nodes' => $this->visibleOutputs($visible, $results, $scope),
            'vars' => new VariableData($execution),
            'store' => new StoreData($execution->workspace, $this->store),
            'secrets' => $execution->workspace->secrets()->get()->pluck('value', 'key')->all(),
            'execution' => $this->aboutRun($execution),
        ];

        $data = [...$data, ...$this->loopData($scope, $results)];

        if ($beforeFirstPass) {
            $data['loop'] = ['index' => null, 'previous' => null];
        }

        if ($step->entry_port === 'error' && $step->previousStep) {
            $data['error'] = $step->previousStep->error;
        }

        return $data;
    }

    /**
     * The data a run's variables can read for their starting values, before
     * any step has run: the trigger input, the data store, and the run.
     *
     * @return array<string, mixed>
     */
    public function atStart(Execution $execution): array
    {
        return [
            'trigger' => $execution->input ?? [],
            'store' => new StoreData($execution->workspace, $this->store),
            'execution' => $this->aboutRun($execution),
        ];
    }

    /**
     * @return array{id: string, workflow: string, trigger_type: string, trigger: array<string, mixed>|null, started_at: string|null}
     */
    protected function aboutRun(Execution $execution): array
    {
        return [
            'id' => $execution->id,
            'workflow' => $execution->workflow->name,
            'trigger_type' => $execution->trigger_type,
            'trigger' => $execution->triggerDetails(),
            'started_at' => $execution->started_at?->toIso8601String(),
        ];
    }

    /**
     * The item and loop a step in $scope reads. loop describes the
     * innermost loop around it. item is the current item of the nearest
     * For loop, so a While loop inside a For loop can still read it.
     *
     * @return array{item?: mixed, loop?: array<string, mixed>}
     */
    protected function loopData(string $scope, LoopResults $results): array
    {
        $data = [];

        for ($at = $scope; $frame = Scope::innermost($at); $at = Scope::parent($at)) {
            $state = ExecutionStep::find($frame['loop_step_id'])->state ?? [];
            $index = $frame['index'];

            if (! ($state['repeat'] ?? false)) {
                $items = $state['items'] ?? [];
                $data['item'] = $items[$index] ?? null;
                $data['loop'] ??= ['index' => $index, 'item' => $data['item'], 'count' => count($items)];

                break;
            }

            $data['loop'] ??= [
                'index' => $index,
                'previous' => $index > 0 ? $results->nodesIn(Scope::forIteration(Scope::parent($at), $frame['loop_step_id'], $index - 1)) : null,
            ];
        }

        return $data;
    }

    /**
     * @param  iterable<ExecutionStep>  $completed
     * @return array<string, array{output: mixed, port: string|null}>
     */
    protected function visibleOutputs(iterable $completed, LoopResults $results, string $scope): array
    {
        $outputs = [];

        foreach ($completed as $done) {
            if (Scope::canSee($scope, $done->scope_key)) {
                $outputs[$done->node_id] = $results->entry($done);
            }
        }

        return $outputs;
    }
}
