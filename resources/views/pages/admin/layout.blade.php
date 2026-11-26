@props(['heading', 'subheading' => null])

<div>
    <x-page-heading :heading="__('navigation.org_settings')" :subheading="__('admin.layout.workspaces_users_sign_in_and')" />

    <div class="mb-8 border-b border-zinc-200 dark:border-zinc-700">
        <flux:navbar scrollable class="-mb-px [scrollbar-width:none]" :aria-label="__('navigation.org_settings')" data-test="org-settings-tabs">
            <flux:navbar.item :href="route('admin.workspaces')" :current="request()->routeIs('admin.workspaces')" wire:navigate>{{ __('common.workspaces') }}</flux:navbar.item>
            <flux:navbar.item :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>{{ __('common.users') }}</flux:navbar.item>
            <flux:navbar.item :href="route('admin.sign-in-providers')" :current="request()->routeIs('admin.sign-in-providers')" wire:navigate>{{ __('admin.sign_in_providers.title') }}</flux:navbar.item>
            <flux:navbar.item :href="route('admin.email')" :current="request()->routeIs('admin.email')" wire:navigate>{{ __('admin.email.title') }}</flux:navbar.item>
            <flux:navbar.item :href="route('admin.settings')" :current="request()->routeIs('admin.settings')" wire:navigate>{{ __('admin.settings.title') }}</flux:navbar.item>

            <flux:spacer />

            <flux:navbar.item :href="route('admin.audit')" icon:trailing="arrow-up-right" :current="false" wire:navigate>{{ __('common.audit_log') }}</flux:navbar.item>
        </flux:navbar>
    </div>

    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="lg" level="2">{{ $heading }}</flux:heading>
            @if ($subheading)
                <flux:subheading>{{ $subheading }}</flux:subheading>
            @endif
        </div>

        @isset($actions)
            <div class="flex items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>

    {{ $slot }}
</div>
