<?php

namespace App\Engine;

use App\Enums\StepStatus;
use App\Models\Execution;
use App\Models\ExecutionStep;

/**
 * Builds the data a step can read: the trigger input, outputs of earlier
 * nodes it can see, workspace values, and the current loop item.
 *
 *     trigger            the execution's input
 *     nodes.<id>.output  the latest output of that node visible from this step;
 *                        a finished loop's includes its results (LoopResults)
 *     vars.<key>         workspace variables
 *     secrets.<key>      workspace secrets (in memory only; masked in logs)
 *     execution          id, workflow, trigger type, started_at, and
 *                        trigger: the id, node type, and settings of the
 *                        trigger node that started the run
 *     item, loop         the current For loop item, index and count
 *     error              for steps reached through an error output
 */
class ContextBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(ExecutionStep $step): array
    {
        $execution = $step->execution;

        $data = [
            'trigger' => $execution->input ?? [],
            'nodes' => $this->visibleOutputs($execution, $step),
            'vars' => $execution->workspace->variables()->pluck('value', 'key')->all(),
            'secrets' => $execution->workspace->secrets()->get()->pluck('value', 'key')->all(),
            'execution' => [
                'id' => $execution->id,
                'workflow' => $execution->workflow->name,
                'trigger_type' => $execution->trigger_type,
                'trigger' => $execution->triggerDetails(),
                'started_at' => $execution->started_at?->toIso8601String(),
            ],
        ];

        if ($frame = Scope::innermost($step->scope_key)) {
            $loopStep = ExecutionStep::find($frame['loop_step_id']);
            $items = $loopStep->state['items'] ?? [];

            $data['item'] = $items[$frame['index']] ?? null;
            $data['loop'] = ['index' => $frame['index'], 'item' => $data['item'], 'count' => count($items)];
        }

        if ($step->entry_port === 'error' && $step->previousStep) {
            $data['error'] = $step->previousStep->error;
        }

        return $data;
    }

    /**
     * @return array<string, array{output: mixed, port: string|null}>
     */
    protected function visibleOutputs(Execution $execution, ExecutionStep $step): array
    {
        $outputs = [];

        $completed = $execution->steps()
            ->where('status', StepStatus::Complete)
            ->orderBy('id')
            ->get(['id', 'node_id', 'scope_key', 'status', 'wait_type', 'output', 'output_ports']);

        $results = new LoopResults($completed);

        foreach ($completed as $done) {
            if (Scope::canSee($step->scope_key, $done->scope_key)) {
                $outputs[$done->node_id] = $results->entry($done);
            }
        }

        return $outputs;
    }
}
