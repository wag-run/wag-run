<?php

use App\Enums\WorkspacePermission;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Workspace $workspace;

    #[Computed]
    public function role(): ?\App\Enums\WorkspaceRole
    {
        return Auth::user()->roleIn($this->workspace);
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function counts(): array
    {
        return [
            'variables' => $this->workspace->variables()->count(),
            'secrets' => $this->workspace->secrets()->count(),
            'credentials' => $this->workspace->credentials()->count(),
            'members' => $this->workspace->members()->count(),
        ];
    }

    public function render()
    {
        return $this->view()->title($this->workspace->name);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="$workspace->name">
        <x-slot:actions>
            <flux:badge>{{ $this->role?->label() }}</flux:badge>
        </x-slot:actions>
    </x-page-heading>

    @if ($this->role?->can(WorkspacePermission::ViewWorkflows))
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['label' => __('Variables'), 'count' => $this->counts['variables'], 'route' => 'workspaces.variables', 'icon' => 'variable'],
                ['label' => __('Secrets'), 'count' => $this->counts['secrets'], 'route' => 'workspaces.secrets', 'icon' => 'lock-closed'],
                ['label' => __('Authentication'), 'count' => $this->counts['credentials'], 'route' => 'workspaces.credentials', 'icon' => 'key'],
                ['label' => __('Members'), 'count' => $this->counts['members'], 'route' => 'workspaces.members', 'icon' => 'users'],
            ] as $card)
                <a href="{{ route($card['route'], $workspace) }}" wire:navigate wire:key="card-{{ $card['route'] }}"
                   class="rounded-xl border border-zinc-200 p-4 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-700/40">
                    <div class="flex items-center gap-2 text-zinc-500">
                        <flux:icon :name="$card['icon']" variant="mini" />
                        <span class="text-sm">{{ $card['label'] }}</span>
                    </div>
                    <div class="mt-2 text-2xl font-semibold">{{ $card['count'] }}</div>
                </a>
            @endforeach
        </div>

        <div class="mt-8">
            <div class="mb-2 flex items-center justify-between">
                <flux:heading>{{ __('Workflows') }}</flux:heading>
                <flux:link :href="route('workspaces.workflows', $workspace)" wire:navigate class="text-sm">{{ __('All workflows') }}</flux:link>
            </div>
            <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($workspace->workflows()->with('publishedVersion')->latest('updated_at')->limit(8)->get() as $workflow)
                    <a href="{{ route('workspaces.workflows.edit', [$workspace, $workflow]) }}" wire:navigate wire:key="wf-{{ $workflow->id }}"
                       class="flex items-center justify-between px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-700/40">
                        <span class="font-medium">{{ $workflow->name }}</span>
                        @if ($workflow->publishedVersion)
                            <flux:badge size="sm" color="green">{{ __('v:n', ['n' => $workflow->publishedVersion->number]) }}</flux:badge>
                        @else
                            <flux:badge size="sm">{{ __('Draft') }}</flux:badge>
                        @endif
                    </a>
                @empty
                    <div class="p-8 text-center text-sm text-zinc-500">{{ __('This workspace has no workflows yet.') }}</div>
                @endforelse
            </div>
        </div>
    @else
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
            <flux:heading>{{ __('Your approvals and tasks') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Approvals and tasks assigned to you in this workspace appear here. Nothing is waiting on you.') }}</flux:text>
        </div>
    @endif
</section>
