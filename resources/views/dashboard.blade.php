<x-layouts::app :title="__('Dashboard')">
    <div class="mx-auto flex max-w-xl flex-col items-center gap-4 py-24 text-center">
        <flux:icon.squares-2x2 class="size-10 text-zinc-400" />
        <flux:heading size="lg">{{ __('No workspaces yet') }}</flux:heading>

        @if ($isInstallAdmin)
            <flux:text>{{ __('Create a workspace to start building workflows.') }}</flux:text>
            <flux:button variant="primary" :href="route('admin.workspaces')" wire:navigate>{{ __('Create a workspace') }}</flux:button>
        @else
            <flux:text>{{ __('You don\'t have access to any workspaces. Ask a workspace admin to add you.') }}</flux:text>
        @endif
    </div>
</x-layouts::app>
