<?php

namespace App\Http\Resources;

use App\Models\ExecutionStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One attempt at one node of a run.
 *
 * @mixin ExecutionStep
 */
class ExecutionStepResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'node_id' => $this->node_id,
            'node_type' => $this->node_type,
            'attempt' => $this->attempt,
            'iteration' => $this->scope_key === '' ? null : $this->scope_key,
            'status' => $this->status->value,
            'input' => $this->input,
            'output' => $this->output,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
