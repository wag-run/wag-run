<?php

namespace App\Services;

use App\Engine\Engine;
use App\Engine\NodeContext;
use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\WorkspaceRole;
use App\Models\Assignment;
use App\Models\Execution;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Approvals and tasks sent to named people. An assignee who is not a member
 * of the workspace is added as a Guest, who sees only what is assigned to them.
 */
class Assignments
{
    public function __construct(
        protected WorkspaceManager $workspaces,
        protected Engine $engine,
    ) {}

    /**
     * The user an approval or task node is configured to assign, if they have
     * an active account on the install.
     */
    public function activeUser(mixed $id): ?User
    {
        $user = is_numeric($id) ? User::query()->find((int) $id) : null;

        return $user && $user->organizationMembership() && ! $user->isDeactivated() ? $user : null;
    }

    /**
     * Expire the assignment a timed-out step was waiting on.
     *
     * @param  array<string, mixed>  $state
     */
    public function expireFromState(array $state): void
    {
        $id = $state['assignment_id'] ?? null;

        if (is_numeric($id) && $assignment = Assignment::query()->find((int) $id)) {
            $this->expire($assignment);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    public function create(NodeContext $context, AssignmentType $type, User $assignee, string $title, ?string $message, ?CarbonInterface $dueAt, array $fields = []): Assignment
    {
        $workspace = $context->workspace();

        if (! $assignee->roleIn($workspace)) {
            $this->workspaces->addMember($workspace, $assignee, WorkspaceRole::Guest);
        }

        return Assignment::create([
            'workspace_id' => $workspace->id,
            'execution_id' => $context->execution->id,
            'execution_step_id' => $context->step->id,
            'assignee_id' => $assignee->id,
            'type' => $type,
            'status' => AssignmentStatus::Pending,
            'title' => $title,
            'message' => $message,
            'fields' => $fields ?: null,
            'due_at' => $dueAt,
        ]);
    }

    public function approve(Assignment $assignment, User $user, ?string $comment = null): void
    {
        $this->decide($assignment, $user, AssignmentStatus::Approved, ['decision' => 'approved', 'comment' => $comment]);
    }

    public function reject(Assignment $assignment, User $user, ?string $comment = null): void
    {
        $this->decide($assignment, $user, AssignmentStatus::Rejected, ['decision' => 'rejected', 'comment' => $comment]);
    }

    /**
     * Submit a task's form. Values are validated against the task's fields.
     *
     * @param  array<string, mixed>  $values
     */
    public function submit(Assignment $assignment, User $user, array $values): void
    {
        $rules = [];

        foreach ($assignment->fields ?? [] as $field) {
            $rules[$field['name']] = static::rulesFor($field);
        }

        $validated = Validator::make($values, $rules)->validate();

        $this->decide($assignment, $user, AssignmentStatus::Submitted, ['values' => $validated], AssignmentType::Task);
    }

    /**
     * Mark a pending assignment expired after its step timed out.
     */
    public function expire(Assignment $assignment): void
    {
        $assignment->newQuery()->whereKey($assignment->id)->where('status', AssignmentStatus::Pending)->update(['status' => AssignmentStatus::Expired]);
    }

    /**
     * Withdraw every pending assignment of an execution that has finished.
     */
    public function cancelFor(Execution $execution): void
    {
        Assignment::query()
            ->where('execution_id', $execution->id)
            ->where('status', AssignmentStatus::Pending)
            ->update(['status' => AssignmentStatus::Cancelled]);
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    public static function rulesFor(array $field): array
    {
        $rules = [($field['required'] ?? false) ? 'required' : 'nullable'];

        $rules[] = match ($field['type'] ?? 'text') {
            'number' => 'numeric',
            'email' => 'email',
            'checkbox' => 'boolean',
            'date' => 'date',
            'select' => 'in:'.implode(',', array_map('strval', array_keys((array) ($field['options'] ?? [])))),
            default => 'string',
        };

        if (in_array($field['type'] ?? 'text', ['text', 'textarea', 'email'], true)) {
            $rules[] = 'max:10000';
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function decide(Assignment $assignment, User $user, AssignmentStatus $status, array $response, AssignmentType $expected = AssignmentType::Approval): void
    {
        if ($assignment->type !== $expected) {
            throw ValidationException::withMessages(['assignment' => __('This is not a :type.', ['type' => $expected->value])]);
        }

        if ($assignment->assignee_id !== $user->id) {
            throw ValidationException::withMessages(['assignment' => __('Only the assignee can respond.')]);
        }

        DB::transaction(function () use ($assignment, $user, $status, $response) {
            $updated = $assignment->newQuery()
                ->whereKey($assignment->id)
                ->where('status', AssignmentStatus::Pending)
                ->update([
                    'status' => $status,
                    'response' => json_encode($response),
                    'responded_by' => $user->id,
                    'responded_at' => now(),
                ]);

            if ($updated === 0) {
                throw ValidationException::withMessages(['assignment' => __('This has already been answered, expired or was withdrawn.')]);
            }

            $this->engine->respond($assignment->step, [...$response, 'responded_by' => $user->id, 'responded_by_name' => $user->name]);
        });

        $assignment->refresh();
    }
}
