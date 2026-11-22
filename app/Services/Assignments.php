<?php

namespace App\Services;

use App\Engine\Engine;
use App\Engine\NodeContext;
use App\Enums\AssignmentStatus;
use App\Enums\AssignmentType;
use App\Enums\WorkspaceRole;
use App\Models\Assignment;
use App\Models\Execution;
use App\Models\ExecutionStep;
use App\Models\User;
use App\Notifications\AssignmentNotification;
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
     * an active account in the organization.
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
        $ids = array_filter([...(array) ($state['assignment_ids'] ?? []), $state['assignment_id'] ?? null], fn (mixed $id) => is_string($id) && $id !== '');

        foreach (Assignment::query()->whereKey($ids)->get() as $assignment) {
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

        $assignment = Assignment::create([
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

        DB::afterCommit(fn () => $assignee->notify(new AssignmentNotification($assignment)));

        return $assignment;
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

        $validated = static::castValues($assignment->fields ?? [], Validator::make($values, $rules)->validate());

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
     * Turn validated form values into their field types: numbers become
     * ints or floats and checkboxes booleans, so later nodes can compare them.
     *
     * @param  iterable<mixed>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function castValues(iterable $fields, array $values): array
    {
        foreach ($fields as $field) {
            $name = is_array($field) ? ($field['name'] ?? null) : null;

            if (! is_string($name) || ! array_key_exists($name, $values) || $values[$name] === null || $values[$name] === '') {
                continue;
            }

            $values[$name] = match ($field['type'] ?? 'text') {
                'number' => is_numeric($values[$name]) ? $values[$name] + 0 : $values[$name],
                'checkbox' => (bool) $values[$name],
                default => $values[$name],
            };
        }

        return $values;
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
     * Whether an approval step's answers so far decide it: approved once the
     * number needed have approved, rejected once so many have rejected that
     * the number can no longer be reached. Pending requests of a decided step
     * are withdrawn. Null while it is still open.
     *
     * @param  array<string, mixed>  $latest  the answer just given
     * @return array<string, mixed>|null
     */
    protected function approvalOutcome(ExecutionStep $step, array $latest): ?array
    {
        $order = array_flip(array_values((array) ($step->state['assignment_ids'] ?? [])));
        $assignments = Assignment::query()
            ->with('assignee:id,name')
            ->where('execution_step_id', $step->id)
            ->where('type', AssignmentType::Approval)
            ->get()
            ->sortBy(fn (Assignment $assignment) => $order[$assignment->id] ?? PHP_INT_MAX)
            ->values();

        $required = max(1, (int) ($step->state['required'] ?? 1));
        $approvals = $assignments->where('status', AssignmentStatus::Approved)->count();
        $rejections = $assignments->where('status', AssignmentStatus::Rejected)->count();

        $decision = match (true) {
            $approvals >= $required => 'approved',
            $rejections > $assignments->count() - $required => 'rejected',
            default => null,
        };

        if ($decision === null) {
            return null;
        }

        Assignment::query()
            ->whereKey($assignments->where('status', AssignmentStatus::Pending)->pluck('id'))
            ->update(['status' => AssignmentStatus::Cancelled]);

        return [
            ...$latest,
            'decision' => $decision,
            'approvals' => $approvals,
            'rejections' => $rejections,
            'responses' => $assignments
                ->filter(fn (Assignment $assignment) => in_array($assignment->status, [AssignmentStatus::Approved, AssignmentStatus::Rejected], true))
                ->map(fn (Assignment $assignment) => [
                    'user_id' => $assignment->assignee_id,
                    'name' => $assignment->assignee?->name,
                    'decision' => $assignment->response['decision'] ?? null,
                    'comment' => $assignment->response['comment'] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $response
     */
    protected function decide(Assignment $assignment, User $user, AssignmentStatus $status, array $response, AssignmentType $expected = AssignmentType::Approval): void
    {
        if ($assignment->type !== $expected) {
            throw ValidationException::withMessages(['assignment' => __('inbox.this_is_not_a_type', ['type' => $expected->value])]);
        }

        if ($assignment->assignee_id !== $user->id) {
            throw ValidationException::withMessages(['assignment' => __('inbox.only_the_assignee_can_respond')]);
        }

        DB::transaction(function () use ($assignment, $user, $status, $response) {
            $step = ExecutionStep::query()->lockForUpdate()->findOrFail($assignment->execution_step_id);

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
                throw ValidationException::withMessages(['assignment' => __('inbox.this_has_already_been_answered')]);
            }

            $payload = [...$response, 'responded_by' => $user->id, 'responded_by_name' => $user->name];

            if ($assignment->type === AssignmentType::Approval) {
                $payload = $this->approvalOutcome($step, $payload);
            }

            if ($payload !== null) {
                $this->engine->respond($step, $payload);
            }
        });

        $user->unreadNotifications()
            ->where('type', AssignmentNotification::class)
            ->where('data->assignment_id', $assignment->id)
            ->update(['read_at' => now()]);

        $assignment->refresh();
    }
}
