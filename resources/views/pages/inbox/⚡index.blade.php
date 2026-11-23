<?php

use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The inbox: every notification the user has received, newest first. An
 * approval or task opens the request itself and carries a badge saying
 * whether it still needs action, or how it ended: approved, rejected,
 * done (a submitted task), expired or withdrawn. Items the user has opened are greyed out.
 */
new class extends Component {
    use WithPagination;

    /**
     * @return LengthAwarePaginator<int, DatabaseNotification>
     */
    #[Computed]
    public function items(): LengthAwarePaginator
    {
        return Auth::user()->notifications()->paginate(25);
    }

    /**
     * The approvals and tasks this page's items point to, by ID.
     *
     * @return Collection<string, Assignment>
     */
    #[Computed]
    public function assignments(): Collection
    {
        $ids = collect($this->items->items())->pluck('data.assignment_id')->filter()->unique()->values();

        return $ids->isEmpty() ? collect() : Assignment::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * Mark an item read and go where it points.
     */
    public function open(string $id): void
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $this->redirect($notification->data['url'] ?? route('dashboard'), navigate: true);
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications()->update(['read_at' => now()]);
        unset($this->items);
    }

    public function render()
    {
        return $this->view()->title(__('inbox.index.title'));
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-heading :heading="__('common.inbox')" :subheading="__('inbox.index.subheading')">
        <x-slot:actions>
            @if (Auth::user()->unreadNotifications()->exists())
                <flux:button size="sm" wire:click="markAllRead" data-test="mark-all-read">{{ __('inbox.index.mark_all_read') }}</flux:button>
            @endif
        </x-slot:actions>
    </x-page-heading>

    <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->items as $item)
            @php($assignment = $this->assignments->get($item->data['assignment_id'] ?? ''))
            <button type="button" wire:key="item-{{ $item->id }}" wire:click="open('{{ $item->id }}')" data-test="inbox-item"
                @class([
                    'flex w-full items-start gap-3 px-4 py-3 text-start hover:bg-zinc-50 dark:hover:bg-zinc-700/40',
                    'opacity-60' => $item->read_at,
                ])>
                <flux:icon :name="$item->data['icon'] ?? 'bell'" variant="mini" class="mt-0.5 {{ $item->read_at ? 'text-zinc-400' : 'text-accent' }}" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate {{ $item->read_at ? 'text-zinc-500' : 'font-semibold' }}">{{ $item->data['title'] ?? '' }}</span>
                    <span class="block truncate text-sm text-zinc-500">{{ $item->data['body'] ?? '' }}</span>
                </span>
                @if ($assignment)
                    @if ($assignment->status === AssignmentStatus::Pending)
                        <flux:badge size="sm" color="amber" data-test="action-needed">{{ __('inbox.index.action_needed') }}</flux:badge>
                    @elseif ($assignment->status === AssignmentStatus::Submitted)
                        <flux:badge size="sm" color="green" data-test="done">{{ __('inbox.index.done') }}</flux:badge>
                    @else
                        <flux:badge size="sm" :color="$assignment->status->color()" data-test="outcome">{{ $assignment->status->label() }}</flux:badge>
                    @endif
                @endif
                <span class="shrink-0 text-xs text-zinc-500">{{ $item->created_at->diffForHumans() }}</span>
            </button>
        @empty
            <div class="p-8 text-center text-sm text-zinc-500" data-test="inbox-empty">{{ __('inbox.index.nothing_here_yet') }}</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $this->items->links() }}</div>
</section>
