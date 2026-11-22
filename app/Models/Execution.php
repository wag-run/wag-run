<?php

namespace App\Models;

use App\Enums\ExecutionStatus;
use App\Enums\StepStatus;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One persisted run of a workflow, pinned to the version it started on.
 *
 * @property string $id a UUID
 * @property int $workspace_id
 * @property string $workflow_id
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
 * @property int $chain_depth 1 for a run nothing else started; one more than its parent's for a run another workflow started
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property-read Workflow $workflow
 * @property-read WorkflowVersion $version
 * @property-read Workspace $workspace
 * @property-read ExecutionStep|null $parentStep
 */
#[Fillable(['workspace_id', 'workflow_id', 'workflow_version_id', 'status', 'trigger_type', 'trigger_node_id', 'input', 'output', 'error', 'triggered_by', 'cancelled_by', 'parent_step_id', 'chain_depth', 'started_at', 'finished_at'])]
class Execution extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'status' => ExecutionStatus::class,
            'input' => 'array',
            'output' => 'array',
            'error' => 'array',
            'chain_depth' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * A short reference for people to read and quote: the last eight
     * characters of a run's UUID. The leading characters are a timestamp, so
     * runs started close together share them.
     */
    public static function reference(string $id): string
    {
        return substr(str_replace('-', '', $id), -8);
    }

    public function isInProgress(): bool
    {
        return in_array($this->status, ExecutionStatus::inProgress(), true);
    }

    /**
     * How long the run took, or has taken so far, as "2m 5s".
     */
    public function durationForHumans(): ?string
    {
        if (! $this->started_at) {
            return null;
        }

        $seconds = (int) $this->started_at->diffInSeconds($this->finished_at ?? now());

        return $seconds < 1 ? __('runs.1s') : CarbonInterval::seconds($seconds)->cascade()->forHumans(short: true, parts: 2);
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
