<div class="flex items-start max-md:flex-col">
    <div class="me-10 w-full pb-4 md:w-[220px]">
        <flux:navlist aria-label="{{ __('navigation.preferences') }}">
            <flux:navlist.item :href="route('profile.edit')" wire:navigate>{{ __('common.profile') }}</flux:navlist.item>
            @if (\App\Models\Organization::current()->password_sign_in ?? true)
                <flux:navlist.item :href="route('security.edit')" wire:navigate>{{ __('settings.layout.security') }}</flux:navlist.item>
            @endif
            <flux:navlist.item :href="route('appearance.edit')" wire:navigate>{{ __('common.appearance') }}</flux:navlist.item>
            <flux:navlist.item :href="route('notifications.edit')" wire:navigate>{{ __('settings.layout.notifications') }}</flux:navlist.item>
            <flux:navlist.item :href="route('api-tokens.edit')" wire:navigate>{{ __('settings.layout.api_tokens') }}</flux:navlist.item>
        </flux:navlist>
    </div>

    <flux:separator class="md:hidden" />

    <div class="flex-1 self-stretch max-md:pt-6">
        <flux:heading>{{ $heading ?? '' }}</flux:heading>
        <flux:subheading>{{ $subheading ?? '' }}</flux:subheading>

        <div class="mt-5 w-full max-w-lg">
            {{ $slot }}
        </div>
    </div>
</div>
