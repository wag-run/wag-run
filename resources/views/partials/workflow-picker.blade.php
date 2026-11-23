{{-- Data picker: inserts a reference to an earlier output or workspace value
     at the cursor of the sibling x-ref="input" field. --}}
@php
    $wrap = fn (string $path): string => implode('', ['{', '{ ', $path, ' }', '}']);
@endphp
<flux:dropdown position="bottom" align="start">
    <flux:button size="xs" variant="subtle" icon="variable" class="mt-1" data-test="data-picker">{{ __('workflows.editor.insert_data') }}</flux:button>
    <flux:menu class="max-h-96 w-80 overflow-y-auto">
        @forelse ($picker as $group)
            <flux:menu.group :heading="$group['label']">
                @foreach ($group['items'] as $item)
                    <flux:menu.item
                        data-token="{{ $wrap($item['path']) }}"
                        x-on:click="insert($el.dataset.token)"
                    >
                        <span class="truncate">{{ $item['label'] }}</span>
                        <span class="ms-auto ps-2 font-mono text-[10px] text-zinc-400">{{ $item['type'] }}</span>
                    </flux:menu.item>
                @endforeach
            </flux:menu.group>
        @empty
            <flux:menu.item disabled>{{ __('workflows.editor.connect_this_node_to_a') }}</flux:menu.item>
        @endforelse
    </flux:menu>
</flux:dropdown>
