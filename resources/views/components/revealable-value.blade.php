@props(['reveal', 'canReveal' => false])

{{-- Shows a masked value. Admins can reveal it with the eye button, which calls
     the given Livewire method; the value is fetched on demand and never kept in
     component state. --}}
<div x-data="{ value: null, shown: false, loading: false }" class="flex items-center gap-1">
    <span class="font-mono text-sm" x-show="! shown">••••••••••••</span>
    <span class="font-mono text-sm break-all" x-show="shown" x-text="value" x-cloak></span>

    @if ($canReveal)
        <flux:button
            size="xs"
            variant="ghost"
            x-on:click="if (shown) { shown = false; value = null; return; } loading = true; value = await $wire.{{ $reveal }}; shown = true; loading = false"
            x-bind:disabled="loading"
            :aria-label="__('ui.reveal_value')"
            data-test="reveal-button"
        >
            <flux:icon.eye variant="micro" x-show="! shown" />
            <flux:icon.eye-slash variant="micro" x-show="shown" x-cloak />
        </flux:button>
    @endif
</div>
