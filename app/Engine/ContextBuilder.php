<?php

namespace App\Engine;

use App\Engine\Masking\MaskedValues;
use App\Engine\Masking\RunMaskedValues;
use App\Enums\MaskedSlot;
use App\Enums\StepStatus;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Services\DataStore;
use Illuminate\Database\Eloquent\Collection;

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
 *
 * Values masked in the run's rows (see Redactor) are put back from
 * MaskedValues, in memory only, before loop results are nested, so a step
 * reads the real token an earlier step received. Nothing that shows a run
 * builds its data here.
 */
class ContextBuilder
{
    public function __construct(protected DataStore $store, protected MaskedValues $masked) {}

    /**
     * @param  string|null  $scope  read the run's data from this scope instead of the step's own, as a loop does when it looks at the pass it just finished
     * @param  bool  $beforeFirstPass  for a loop that repeats, before it has run a pass: loop is its own, with no pass in it yet
     * @return array<string, mixed>
     */
    public function build(ExecutionStep $step, ?string $scope = null, bool $beforeFirstPass = false): array
    {
        $execution = $step->execution;
        $scope ??= $step->scope_key;

        $kept = $this->masked->of($execution);

        $visible = $this->completedSteps($execution, $kept, Scope::visibleFrom($scope));
        $results = new LoopResults(fn () => $this->completedSteps($execution, $kept));

        $data = [
            'trigger' => $kept->restore($execution->input ?? [], MaskedSlot::Trigger),
            'nodes' => $this->visibleOutputs($visible, $results, $scope),
            'vars' => new VariableData($execution, unmasked: true),
            'store' => new StoreData($execution->workspace, $this->store),
            'secrets' => $execution->workspace->secrets()->get()->pluck('value', 'key')->all(),
            'execution' => $this->aboutRun($execution),
        ];

        $data = [...$data, ...$this->loopData($scope, $results, $kept)];

        if ($beforeFirstPass) {
            $data['loop'] = ['index' => null, 'previous' => null];
        }

        if ($step->entry_port === 'error' && $step->previousStep) {
            $data['error'] = $step->previousStep->error;
        }

        return $data;
    }

    /**
     * The run's completed steps, oldest first, with the real values put back
     * into their outputs.
     *
     * @param  list<string>|null  $scopes  only the steps in these scopes, or the whole run
     * @return Collection<int, ExecutionStep>
     */
    protected function completedSteps(Execution $execution, RunMaskedValues $kept, ?array $scopes = null): Collection
    {
        $steps = $execution->steps()
            ->where('status', StepStatus::Complete)
            ->when($scopes !== null, fn ($query) => $query->whereIn('scope_key', $scopes))
            ->orderBy('id')
            ->get(['id', 'node_id', 'scope_key', 'status', 'wait_type', 'output', 'output_ports']);

        foreach ($steps as $done) {
            if ($done->output !== null) {
                $done->output = $kept->restore($done->output, MaskedSlot::Output, $done->id);
            }
        }

        return $steps;
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
            'trigger' => $this->masked->of($execution)->restore($execution->input ?? [], MaskedSlot::Trigger),
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
    protected function loopData(string $scope, LoopResults $results, RunMaskedValues $kept): array
    {
        $data = [];

        for ($at = $scope; $frame = Scope::innermost($at); $at = Scope::parent($at)) {
            $state = $kept->restore(ExecutionStep::find($frame['loop_step_id'])->state ?? [], MaskedSlot::State, $frame['loop_step_id']);
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
