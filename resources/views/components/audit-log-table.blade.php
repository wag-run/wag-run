@props(['entries', 'showWorkspace' => false])

<flux:table :paginate="$entries">
    <flux:table.columns>
        <flux:table.column>{{ __('When') }}</flux:table.column>
        <flux:table.column>{{ __('Who') }}</flux:table.column>
        <flux:table.column>{{ __('Action') }}</flux:table.column>
        <flux:table.column>{{ __('Subject') }}</flux:table.column>
        @if ($showWorkspace)
            <flux:table.column>{{ __('Workspace') }}</flux:table.column>
        @endif
        <flux:table.column>{{ __('Details') }}</flux:table.column>
    </flux:table.columns>

    <flux:table.rows>
        @forelse ($entries as $entry)
            <flux:table.row :key="$entry->id">
                <flux:table.cell class="whitespace-nowrap" title="{{ $entry->created_at->toIso8601String() }}">
                    {{ $entry->created_at->diffForHumans() }}
                </flux:table.cell>
                <flux:table.cell>{{ $entry->user?->name ?? __('System') }}</flux:table.cell>
                <flux:table.cell>
                    <flux:badge size="sm" :color="str_ends_with($entry->action, 'revealed') ? 'amber' : (str_ends_with($entry->action, 'deleted') || str_ends_with($entry->action, 'removed') ? 'red' : 'zinc')">
                        {{ ucfirst(str_replace(['.', '_'], ' ', $entry->action)) }}
                    </flux:badge>
                </flux:table.cell>
                <flux:table.cell>{{ $entry->subject_label }}</flux:table.cell>
                @if ($showWorkspace)
                    <flux:table.cell>{{ $entry->workspace?->name ?? ($entry->metadata['workspace'] ?? '—') }}</flux:table.cell>
                @endif
                <flux:table.cell class="text-xs text-zinc-500">
                    @foreach ($entry->metadata ?? [] as $metaKey => $metaValue)
                        <span class="me-2">{{ $metaKey }}: {{ is_array($metaValue) ? implode(', ', $metaValue) : $metaValue }}</span>
                    @endforeach
                </flux:table.cell>
            </flux:table.row>
        @empty
            <flux:table.row>
                <flux:table.cell colspan="6" class="text-center text-zinc-500">{{ __('No entries yet.') }}</flux:table.cell>
            </flux:table.row>
        @endforelse
    </flux:table.rows>
</flux:table>
