@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand :aria-label="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="h-8 text-black dark:text-white">
            <x-app-logo-wordmark class="h-8 w-auto" />
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand :aria-label="config('app.name', 'Laravel')" {{ $attributes }}>
        <x-slot name="logo" class="h-8 text-black dark:text-white">
            <x-app-logo-wordmark class="h-8 w-auto" />
        </x-slot>
    </flux:brand>
@endif
