<?php

namespace App\Models;

use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An approval or task waiting on a named person. The step it belongs to
 * waits until they respond or it times out.
 *
 * @property string $id a UUID
 * @property int $workspace_id
 * @property string $execution_id
 * @property int $execution_step_id
 * @property string $assignee_id
 * @property AssignmentType $type
 * @property AssignmentStatus $status
 * @property string $title
 * @property string|null $message
 * @property list<array<string, mixed>>|null $fields
 * @property array<string, mixed>|null $response
 * @property string|null $responded_by
 * @property Carbon|null $responded_at
 * @property Carbon|null $due_at
 * @property Carbon|null $created_at
 * @property-read User $assignee
 * @property-read ExecutionStep $step
 * @property-read Execution $execution
 * @property-read Workspace $workspace
 */
#[Fillable(['workspace_id', 'execution_id', 'execution_step_id', 'assignee_id', 'type', 'status', 'title', 'message', 'fields', 'response', 'responded_by', 'responded_at', 'due_at'])]
class Assignment extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'type' => AssignmentType::class,
            'status' => AssignmentStatus::class,
            'fields' => 'array',
            'response' => 'array',
            'responded_at' => 'datetime',
            'due_at' => 'datetime',
        ];
    }

    public function isPending(): bool
    {
        return $this->status === AssignmentStatus::Pending;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /**
     * @return BelongsTo<ExecutionStep, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(ExecutionStep::class, 'execution_step_id');
    }

    /**
     * @return BelongsTo<Execution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
