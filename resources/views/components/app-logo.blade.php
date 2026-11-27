@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :aria-label="config('app.name', 'Laravel')" {{ $attributes->class('in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:static! in-data-flux-sidebar-collapsed-desktop:in-data-flux-sidebar-active:opacity-100!') }}>
        <x-slot name="logo" class="h-8 text-black dark:text-white">
            <x-app-logo-wordmark class="h-8 w-auto in-data-flux-sidebar-collapsed-desktop:hidden" />
            <x-app-logo-mark class="hidden size-6 in-data-flux-sidebar-collapsed-desktop:block" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :aria-label="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="h-8 text-black dark:text-white">
            <x-app-logo-wordmark class="h-8 w-auto" />
        </x-slot>
    </flux:brand>
@endif
