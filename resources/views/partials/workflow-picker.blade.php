{{-- Data picker: inserts a reference to an earlier output or workspace value,
     or a call to a helper function, at the cursor of the sibling
     x-ref="input" field. Inside an existing {{ }} placeholder they go in
     bare. $inRow: the button sits in a row of buttons, so it takes no space
     above it. --}}
@php
    $wrap = fn (string $path): string => implode('', ['{', '{ ', $path, ' }', '}']);
@endphp
<flux:dropdown position="bottom" align="start">
    <flux:button size="xs" variant="subtle" icon="variable" @class(['mt-1' => ! ($inRow ?? false)]) data-test="data-picker">{{ __('workflows.editor.insert_data') }}</flux:button>
    <flux:menu class="max-h-96 w-80 overflow-y-auto">
        @forelse ($picker as $group)
            <flux:menu.group :heading="$group['label']">
                @foreach ($group['items'] as $item)
                    <flux:menu.item
                        data-token="{{ $wrap($item['path']) }}"
                        data-path="{{ $item['path'] }}"
                        x-on:click="insertReference($el.dataset.path)"
                    >
                        <span class="truncate">{{ $item['label'] }}</span>
                        <span class="ms-auto ps-2 font-mono text-[10px] text-zinc-400">{{ $item['type'] }}</span>
                    </flux:menu.item>
                @endforeach
            </flux:menu.group>
        @empty
            <flux:menu.item disabled>{{ __('workflows.editor.connect_this_node_to_a') }}</flux:menu.item>
        @endforelse
        <flux:menu.group :heading="__('workflows.editor.functions')" data-test="function-picker">
            @foreach (\App\Engine\Templates\HelperFunctions::catalog() as $category)
                <flux:menu.submenu :heading="$category['label']">
                    @foreach ($category['items'] as $function)
                        <flux:menu.item
                            class="max-w-80"
                            data-function="{{ $function['name'] }}"
                            data-takes-arguments="{{ str_ends_with($function['signature'], '()') ? 'false' : 'true' }}"
                            x-on:click="insertFunction($el.dataset.function, $el.dataset.takesArguments === 'true')"
                        >
                            <span class="min-w-0">
                                <span class="block font-mono text-xs">{{ $function['signature'] }}</span>
                                <span class="block text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ $function['description'] }}</span>
                            </span>
                        </flux:menu.item>
                    @endforeach
                </flux:menu.submenu>
            @endforeach
        </flux:menu.group>
    </flux:menu>
</flux:dropdown>
