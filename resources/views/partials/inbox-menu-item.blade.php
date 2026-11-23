{{-- The Inbox entry of the user menus, with how many items are unread. --}}
<flux:menu.item :href="route('inbox')" icon="inbox" wire:navigate data-test="inbox-link">
    {{ __('common.inbox') }}
    @if ($unread > 0)
        <x-slot:suffix>
            <flux:badge size="sm" color="red" class="ms-auto" data-test="inbox-count">{{ $unread > 99 ? '99+' : $unread }}</flux:badge>
        </x-slot:suffix>
    @endif
</flux:menu.item>
