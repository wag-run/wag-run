<?php

namespace App\Models;

use App\Enums\ExecutionStatus;
use App\Enums\StepStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One persisted run of a workflow, pinned to the version it started on.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $workflow_id
 * @property int $workflow_version_id
 * @property ExecutionStatus $status
 * @property string $trigger_type
 * @property string|null $trigger_node_id
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property array<string, mixed>|null $error
 * @property int|null $triggered_by
 * @property int|null $cancelled_by
 * @property int|null $parent_step_id
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property-read Workflow $workflow
 * @property-read WorkflowVersion $version
 * @property-read Workspace $workspace
 * @property-read ExecutionStep|null $parentStep
 */
#[Fillable(['workspace_id', 'workflow_id', 'workflow_version_id', 'status', 'trigger_type', 'trigger_node_id', 'input', 'output', 'error', 'triggered_by', 'cancelled_by', 'parent_step_id', 'started_at', 'finished_at'])]
class Execution extends Model
{
    protected function casts(): array
    {
        return [
            'status' => ExecutionStatus::class,
            'input' => 'array',
            'output' => 'array',
            'error' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workflow, $this>
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * @return BelongsTo<WorkflowVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<ExecutionStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(ExecutionStep::class);
    }

    /**
     * @return HasMany<ExecutionStep, $this>
     */
    public function openSteps(): HasMany
    {
        return $this->steps()->whereIn('status', StepStatus::open());
    }

    /**
     * @return BelongsTo<ExecutionStep, $this>
     */
    public function parentStep(): BelongsTo
    {
        return $this->belongsTo(ExecutionStep::class, 'parent_step_id');
    }

    /**
     * @return HasMany<Execution, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Execution::class, 'parent_step_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
