<?php

use App\Enums\AssignmentType;
use App\Models\Assignment;
use App\Models\Workspace;
use App\Notifications\AssignmentNotification;
use App\Services\Assignments;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public Workspace $workspace;

    public Assignment $assignment;

    public string $comment = '';

    /**
     * @var array<string, mixed>
     */
    public array $values = [];

    public function mount(): void
    {
        abort_unless($this->assignment->assignee_id === Auth::id(), 403);

        Auth::user()->unreadNotifications()
            ->where('type', AssignmentNotification::class)
            ->where('data->assignment_id', $this->assignment->id)
            ->update(['read_at' => now()]);

        foreach ($this->fields as $field) {
            $this->values[$field['name']] = ($field['type'] ?? 'text') === 'checkbox' ? false : null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function fields(): array
    {
        return array_values(array_filter($this->assignment->fields ?? [], fn (mixed $field) => is_array($field) && filled($field['name'] ?? null)));
    }

    /**
     * Everyone an approval went to and where it stands, when it went to
     * more than one person.
     *
     * @return array{approvers: \Illuminate\Database\Eloquent\Collection<int, Assignment>, required: int, approvals: int, rejections: int, decision: 'approved'|'rejected'|null}|null
     */
    #[Computed]
    public function approval(): ?array
    {
        if ($this->assignment->type !== AssignmentType::Approval || ! $this->assignment->step) {
            return null;
        }

        $progress = app(Assignments::class)->approvalProgress($this->assignment->step);

        return $progress['approvers']->count() > 1 ? $progress : null;
    }

    #[Computed]
    public function canViewRun(): bool
    {
        return Auth::user()->can('view-executions', $this->workspace);
    }

    public function approve(Assignments $assignments): void
    {
        $this->respond(fn () => $assignments->approve($this->assignment, Auth::user(), $this->comment ?: null), __('inbox.show.approved_the_workflow_continues'));
    }

    public function reject(Assignments $assignments): void
    {
        $this->respond(fn () => $assignments->reject($this->assignment, Auth::user(), $this->comment ?: null), __('inbox.show.rejected_the_workflow_continues_down'));
    }

    public function submit(Assignments $assignments): void
    {
        $this->respond(function () use ($assignments) {
            try {
                $assignments->submit($this->assignment, Auth::user(), $this->values);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $key) => [$key === 'assignment' ? 'assignment' : "values.{$key}" => $messages])
                    ->all());
            }
        }, __('inbox.show.submitted_the_workflow_continues'));
    }

    protected function respond(callable $callback, string $message): void
    {
        abort_unless($this->assignment->assignee_id === Auth::id(), 403);
        $this->resetValidation();

        $callback();

        $this->assignment->refresh();
        unset($this->approval);
        Flux::toast(variant: 'success', text: $message);
    }

    public function render()
    {
        return $this->view()->title($this->assignment->title);
    }
}; ?>

<section class="w-full max-w-2xl">
    <flux:link :href="route('inbox')" wire:navigate class="mb-4 inline-flex items-center gap-1 text-sm">
        <flux:icon name="arrow-left" variant="micro" /> {{ __('common.inbox') }}
    </flux:link>

    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $assignment->title }}</flux:heading>
            <flux:text class="mt-1">
                {{ $assignment->type->label() }} · {{ $assignment->execution->workflow->name }}
                @if ($this->canViewRun)
                    · <flux:link :href="route('workspaces.executions.show', [$workspace, $assignment->execution_id])" wire:navigate>{{ __('common.run_id', ['id' => \App\Models\Execution::reference($assignment->execution_id)]) }}</flux:link>
                @endif
            </flux:text>
        </div>
        <flux:badge :color="$assignment->status->color()" data-test="assignment-status">{{ $assignment->status->label() }}</flux:badge>
    </div>

    @if ($assignment->message)
        <div class="mb-6 rounded-xl border border-zinc-200 p-4 text-sm whitespace-pre-line dark:border-zinc-700">{{ $assignment->message }}</div>
    @endif

    @if ($approval = $this->approval)
        <div class="mb-6" data-test="approvers">
            <div class="mb-2 flex items-baseline justify-between gap-4">
                <flux:heading>{{ __('inbox.show.approvers') }}</flux:heading>
                <flux:text class="text-sm" data-test="approval-summary">
                    @if ($approval['decision'] === 'approved')
                        {{ __('inbox.show.summary_approved', ['approvals' => $approval['approvals'], 'required' => $approval['required']]) }}
                    @elseif ($approval['decision'] === 'rejected')
                        {{ __('inbox.show.summary_rejected', ['required' => $approval['required']]) }}
                    @elseif ($approval['approvers']->contains(fn ($approver) => $approver->isPending()))
                        {{ trans_choice('inbox.show.summary_waiting', $approval['required'], ['approvals' => $approval['approvals'], 'required' => $approval['required']]) }}
                    @endif
                </flux:text>
            </div>
            <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($approval['approvers'] as $approver)
                    <div wire:key="approver-{{ $approver->id }}" class="flex items-start gap-3 px-4 py-3" data-test="approver">
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">
                                {{ $approver->assignee?->name ?? __('inbox.show.deleted_user') }}
                                @if ($approver->is($assignment))
                                    <span class="font-normal text-zinc-500">{{ __('inbox.show.you') }}</span>
                                @endif
                            </div>
                            @if ($approver->response['comment'] ?? null)
                                <p class="mt-1 whitespace-pre-line text-zinc-600 dark:text-zinc-300">{{ $approver->response['comment'] }}</p>
                            @endif
                            @if ($approver->responded_at)
                                <div class="mt-1 text-xs text-zinc-500">{{ $approver->responded_at->diffForHumans() }}</div>
                            @endif
                        </div>
                        @if ($approver->isPending())
                            <flux:badge size="sm" color="amber">{{ __('inbox.show.waiting') }}</flux:badge>
                        @else
                            <flux:badge size="sm" :color="$approver->status->color()">{{ $approver->status->label() }}</flux:badge>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <flux:error name="assignment" class="mb-4" />

    @if ($assignment->isPending())
        @if ($assignment->due_at)
            <flux:text class="mb-4 text-sm">{{ __('inbox.show.respond_by_time_relative_with', ['time' => $assignment->due_at->toDayDateTimeString(), 'relative' => $assignment->due_at->diffForHumans(parts: 2)]) }}</flux:text>
        @endif

        @if ($assignment->type === AssignmentType::Approval)
            <div class="space-y-4">
                <flux:textarea wire:model="comment" :label="__('inbox.show.comment_optional')" rows="3" />
                <div class="flex gap-2">
                    <flux:button variant="primary" icon="check" wire:click="approve" data-test="approve-button">{{ __('inbox.show.approve') }}</flux:button>
                    <flux:button variant="danger" icon="x-mark" wire:click="reject" data-test="reject-button">{{ __('inbox.show.reject') }}</flux:button>
                </div>
            </div>
        @else
            <form wire:submit="submit" class="space-y-6">
                @include('partials.form-fields', ['fields' => $this->fields, 'model' => 'values'])
                <flux:button type="submit" variant="primary" data-test="submit-task-button">{{ __('common.submit') }}</flux:button>
            </form>
        @endif
    @elseif ($assignment->response && ! $approval)
        <div class="rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
            <flux:text class="mb-2" data-test="answered">
                @if ($assignment->responder)
                    {{ __('inbox.show.answered_by_name_time', ['name' => $assignment->responder->name, 'time' => $assignment->responded_at?->diffForHumans()]) }}
                @else
                    {{ __('inbox.show.answered_time', ['time' => $assignment->responded_at?->diffForHumans()]) }}
                @endif
            </flux:text>
            @if ($assignment->response['comment'] ?? null)
                <p class="whitespace-pre-line">{{ $assignment->response['comment'] }}</p>
            @endif
            @foreach ($assignment->response['values'] ?? [] as $key => $value)
                <div wire:key="value-{{ $key }}" class="flex gap-2"><span class="text-zinc-500">{{ collect($this->fields)->firstWhere('name', $key)['label'] ?? $key }}:</span> <span>{{ \App\Services\WorkflowFiles::isReference($value) ? $value['name'] : (is_bool($value) ? ($value ? __('inbox.show.yes') : __('inbox.show.no')) : (is_array($value) ? json_encode($value) : $value)) }}</span></div>
            @endforeach
        </div>
    @endif
</section>
