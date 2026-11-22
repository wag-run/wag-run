<?php

use App\Enums\AssignmentType;
use App\Models\Assignment;
use App\Models\Workspace;
use App\Services\Assignments;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
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
        Flux::toast(variant: 'success', text: $message);
    }

    public function render()
    {
        return $this->view()->title($this->assignment->title);
    }
}; ?>

<section class="w-full max-w-2xl">
    <flux:link :href="route('workspaces.inbox', $workspace)" wire:navigate class="mb-4 inline-flex items-center gap-1 text-sm">
        <flux:icon name="arrow-left" variant="micro" /> {{ __('common.inbox') }}
    </flux:link>

    <div class="mb-6 flex items-start justify-between gap-4">
        <div>
            <flux:heading size="xl">{{ $assignment->title }}</flux:heading>
            <flux:text class="mt-1">
                {{ $assignment->type->label() }} · {{ $assignment->execution->workflow->name }}
                @if ($this->canViewRun)
                    · <flux:link :href="route('workspaces.executions.show', [$workspace, $assignment->execution_id])" wire:navigate>{{ __('common.run_id', ['id' => $assignment->execution_id]) }}</flux:link>
                @endif
            </flux:text>
        </div>
        <flux:badge :color="$assignment->status->color()" data-test="assignment-status">{{ $assignment->status->label() }}</flux:badge>
    </div>

    @if ($assignment->message)
        <div class="mb-6 rounded-xl border border-zinc-200 p-4 text-sm whitespace-pre-line dark:border-zinc-700">{{ $assignment->message }}</div>
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
    @elseif ($assignment->response)
        <div class="rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
            <flux:text class="mb-2">{{ __('inbox.show.answered_time', ['time' => $assignment->responded_at?->diffForHumans()]) }}</flux:text>
            @if ($assignment->response['comment'] ?? null)
                <p class="whitespace-pre-line">{{ $assignment->response['comment'] }}</p>
            @endif
            @foreach ($assignment->response['values'] ?? [] as $key => $value)
                <div wire:key="value-{{ $key }}" class="flex gap-2"><span class="text-zinc-500">{{ collect($this->fields)->firstWhere('name', $key)['label'] ?? $key }}:</span> <span>{{ is_bool($value) ? ($value ? __('inbox.show.yes') : __('inbox.show.no')) : $value }}</span></div>
            @endforeach
        </div>
    @endif
</section>
