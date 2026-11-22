<?php

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    /**
     * @return LengthAwarePaginator<int, DatabaseNotification>
     */
    #[Computed]
    public function notifications(): LengthAwarePaginator
    {
        return Auth::user()->notifications()->paginate(25);
    }

    /**
     * Mark a notification read and go where it points.
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
        unset($this->notifications);
    }

    public function render()
    {
        return $this->view()->title(__('notifications.index.title'));
    }
}; ?>

<section class="w-full max-w-3xl">
    <x-page-heading :heading="__('common.notifications')" :subheading="__('notifications.index.failed_runs_of_workflows_you')">
        <x-slot:actions>
            @if (Auth::user()->unreadNotifications()->exists())
                <flux:button size="sm" wire:click="markAllRead" data-test="mark-all-read">{{ __('notifications.index.mark_all_read') }}</flux:button>
            @endif
        </x-slot:actions>
    </x-page-heading>

    <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
        @forelse ($this->notifications as $notification)
            <button type="button" wire:key="notification-{{ $notification->id }}" wire:click="open('{{ $notification->id }}')" data-test="notification"
                class="flex w-full items-start gap-3 px-4 py-3 text-start hover:bg-zinc-50 dark:hover:bg-zinc-700/40">
                <flux:icon :name="$notification->data['icon'] ?? 'bell'" variant="mini" class="mt-0.5 {{ $notification->read_at ? 'text-zinc-400' : 'text-accent' }}" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate {{ $notification->read_at ? '' : 'font-semibold' }}">{{ $notification->data['title'] ?? '' }}</span>
                    <span class="block truncate text-sm text-zinc-500">{{ $notification->data['body'] ?? '' }}</span>
                </span>
                <span class="shrink-0 text-xs text-zinc-500">{{ $notification->created_at->diffForHumans() }}</span>
            </button>
        @empty
            <div class="p-8 text-center text-sm text-zinc-500">{{ __('notifications.index.no_notifications_yet') }}</div>
        @endforelse
    </div>

    <div class="mt-4">{{ $this->notifications->links() }}</div>
</section>
