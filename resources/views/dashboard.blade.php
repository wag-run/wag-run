<x-layouts::app :title="__('common.dashboard')">
    <div class="mx-auto flex max-w-xl flex-col items-center gap-4 py-24 text-center">
        <flux:icon.squares-2x2 class="size-10 text-zinc-400" />
        <flux:heading size="lg">{{ __('dashboard.no_workspaces_yet') }}</flux:heading>

        @if ($isOrgAdmin)
            <flux:text>{{ __('dashboard.create_a_workspace_to_start') }}</flux:text>
            <flux:button variant="primary" :href="route('admin.workspaces')" wire:navigate>{{ __('dashboard.create_a_workspace') }}</flux:button>
        @else
            <flux:text>{{ __('dashboard.you_don_t_have_access') }}</flux:text>
        @endif
    </div>
</x-layouts::app>
