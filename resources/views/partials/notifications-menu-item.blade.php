{{-- The Notifications entry of the user menus, with the unread count. --}}
<flux:menu.item :href="route('notifications')" icon="bell" wire:navigate data-test="notifications-link">
    {{ __('common.notifications') }}
    @if ($unread > 0)
        <x-slot:suffix>
            <flux:badge size="sm" color="red" class="ms-auto" data-test="unread-notifications">{{ $unread > 99 ? '99+' : $unread }}</flux:badge>
        </x-slot:suffix>
    @endif
</flux:menu.item>
