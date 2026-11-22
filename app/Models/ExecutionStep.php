<?php

namespace App\Models;

use App\Enums\StepStatus;
use App\Enums\WaitType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One node's run inside an execution. Each attempt is its own row: a retry is
 * a new pending row and the failed one stays failed.
 *
 * The scope key places the step inside loop iterations: '' at the top level,
 * 'L12.0' in iteration 0 of the loop whose step is 12, 'L12.0/L15.3' nested.
 *
 * @property int $id
 * @property string $execution_id
 * @property string $node_id
 * @property string $node_type
 * @property int $attempt
 * @property int|null $retry_of_id
 * @property int|null $previous_step_id
 * @property string|null $entry_port
 * @property string $scope_key
 * @property StepStatus $status
 * @property WaitType|null $wait_type
 * @property Carbon|null $resume_at
 * @property array<string, mixed>|null $state
 * @property array<string, mixed>|null $input
 * @property mixed $output
 * @property list<string>|null $output_ports
 * @property array<string, mixed>|null $error
 * @property Carbon|null $available_at
 * @property Carbon|null $queued_at
 * @property string|null $claim_token
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property-read Execution $execution
 * @property-read ExecutionStep|null $previousStep
 */
#[Fillable(['execution_id', 'node_id', 'node_type', 'attempt', 'retry_of_id', 'previous_step_id', 'entry_port', 'scope_key', 'status', 'wait_type', 'resume_at', 'state', 'input', 'output', 'output_ports', 'error', 'available_at', 'queued_at', 'claim_token', 'heartbeat_at', 'started_at', 'finished_at'])]
class ExecutionStep extends Model
{
    protected function casts(): array
    {
        return [
            'attempt' => 'integer',
            'status' => StepStatus::class,
            'wait_type' => WaitType::class,
            'resume_at' => 'datetime',
            'state' => 'array',
            'input' => 'array',
            'output' => 'json',
            'output_ports' => 'array',
            'error' => 'array',
            'available_at' => 'datetime',
            'queued_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /**
     * @return BelongsTo<ExecutionStep, $this>
     */
    public function previousStep(): BelongsTo
    {
        return $this->belongsTo(ExecutionStep::class, 'previous_step_id');
    }

    /**
     * @return BelongsTo<ExecutionStep, $this>
     */
    public function retryOf(): BelongsTo
    {
        return $this->belongsTo(ExecutionStep::class, 'retry_of_id');
    }

    /**
     * @return HasMany<Execution, $this>
     */
    public function childExecutions(): HasMany
    {
        return $this->hasMany(Execution::class, 'parent_step_id');
    }
}
