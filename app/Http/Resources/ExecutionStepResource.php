<?php

namespace App\Http\Resources;

use App\Engine\LoopResults;
use App\Models\ExecutionStep;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One attempt at one node of a run. A finished loop's output includes its
 * results when the run's steps are given with withLoopResults().
 *
 * @mixin ExecutionStep
 */
class ExecutionStepResource extends JsonResource
{
    protected ?LoopResults $loopResults = null;

    /**
     * Read a finished loop's results from the run's other steps.
     */
    public function withLoopResults(LoopResults $loopResults): static
    {
        $this->loopResults = $loopResults;

        return $this;
    }

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
            'output' => $this->loopResults ? $this->loopResults->outputOf($this->resource) : $this->output,
            'error' => $this->error,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
