<?php

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Inbox')] class extends Component {
    public Workspace $workspace;

    /**
     * @return Collection<int, Assignment>
     */
    #[Computed]
    public function pending(): Collection
    {
        return $this->mine()->where('status', AssignmentStatus::Pending)->oldest('due_at')->get();
    }

    /**
     * @return Collection<int, Assignment>
     */
    #[Computed]
    public function answered(): Collection
    {
        return $this->mine()->where('status', '!=', AssignmentStatus::Pending)->latest('updated_at')->limit(25)->get();
    }

    /**
     * @return Builder<Assignment>
     */
    protected function mine(): Builder
    {
        return Assignment::query()
            ->with('execution.workflow')
            ->where('workspace_id', $this->workspace->id)
            ->where('assignee_id', Auth::id());
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('Inbox')" :subheading="__('Approvals and tasks assigned to you in :workspace.', ['workspace' => $workspace->name])" />

    <flux:heading class="mb-2">{{ __('Waiting on you') }}</flux:heading>
    <div class="mb-10 divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->pending as $assignment)
            @include('partials.assignment-row', ['assignment' => $assignment])
        @empty
            <div class="p-8 text-center text-sm text-zinc-500" data-test="inbox-empty">{{ __('Nothing is waiting on you.') }}</div>
        @endforelse
    </div>

    @if ($this->answered->isNotEmpty())
        <flux:heading class="mb-2">{{ __('Recently closed') }}</flux:heading>
        <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($this->answered as $assignment)
                @include('partials.assignment-row', ['assignment' => $assignment])
            @endforeach
        </div>
    @endif
</section>
