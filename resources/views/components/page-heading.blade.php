@props(['heading', 'subheading' => null])

<div class="mb-6 flex flex-wrap items-start justify-between gap-4">
    <div>
        <flux:heading size="xl" level="1">{{ $heading }}</flux:heading>
        @if ($subheading)
            <flux:subheading size="lg">{{ $subheading }}</flux:subheading>
        @endif
    </div>

    @isset($actions)
        <div class="flex items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
