<?php

namespace App\Http\Resources;

use App\Models\Execution;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A run. Its steps are included when they were loaded, as they are for a
 * single run but not in a list.
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
            'steps' => ExecutionStepResource::collection($this->whenLoaded('steps')),
        ];
    }
}
