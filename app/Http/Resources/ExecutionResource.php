<?php

namespace App\Http\Resources;

use App\Engine\LoopResults;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\ExecutionVariable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A run. Its steps and the current values of its workflow variables are
 * included when they were loaded, as they are for a single run but not in
 * a list.
 *
 * @mixin Execution
 */
class ExecutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow' => ['id' => $this->workflow_id, 'name' => $this->workflow->name],
            'status' => $this->status->value,
            'trigger_type' => $this->trigger_type,
            'is_test' => $this->is_test,
            'input' => $this->input,
            'output' => $this->output,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'url' => route('workspaces.executions.show', [$this->workspace, $this->resource]),
            'variables' => $this->whenLoaded('variables', fn () => (object) $this->variables
                ->mapWithKeys(fn (ExecutionVariable $variable) => [$variable->name => $variable->decoded()])->all()),
            'steps' => $this->whenLoaded('steps', function () {
                $loopResults = new LoopResults($this->steps);

                return $this->steps->map(fn (ExecutionStep $step) => (new ExecutionStepResource($step))->withLoopResults($loopResults));
            }),
        ];
    }
}
