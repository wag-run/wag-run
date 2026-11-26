@props(['unread' => 0])

<flux:dropdown position="bottom" align="start">
    <flux:sidebar.profile
        :name="auth()->user()->name"
        :initials="auth()->user()->initials()"
        icon:trailing="chevrons-up-down"
        :avatar:badge="$unread > 0"
        avatar:badge:color="red"
        avatar:badge:circle
        avatar:badge:position="top right"
        :aria-label="$unread ? trans_choice('navigation.account_menu_unread', $unread, ['count' => $unread]) : null"
        data-test="sidebar-menu-button"
    />

    <flux:menu>
        <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
            <flux:avatar
                :name="auth()->user()->name"
                :initials="auth()->user()->initials()"
            />
            <div class="grid flex-1 text-start text-sm leading-tight">
                <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
            </div>
        </div>
        <flux:menu.separator />
        <flux:menu.radio.group>
            @include('partials.inbox-menu-item', ['unread' => $unread])
            <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                {{ __('navigation.preferences') }}
            </flux:menu.item>
            @can('org-admin')
                <flux:menu.item :href="route('admin.workspaces')" icon="building-office" wire:navigate data-test="org-settings-menu-item">
                    {{ __('navigation.org_settings') }}
                </flux:menu.item>
            @endcan
            <form method="POST" action="{{ route('logout') }}" class="w-full">
                @csrf
                <flux:menu.item
                    as="button"
                    type="submit"
                    icon="arrow-right-start-on-rectangle"
                    class="w-full cursor-pointer"
                    data-test="logout-button"
                >
                    {{ __('common.log_out') }}
                </flux:menu.item>
            </form>
        </flux:menu.radio.group>
    </flux:menu>
</flux:dropdown>
