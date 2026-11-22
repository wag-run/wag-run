<?php

use App\Enums\WorkspacePermission;
use App\Enums\AssignmentStatus;
use App\Models\Assignment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
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
     * Approvals and tasks waiting on the signed-in user here.
     *
     * @return Collection<int, Assignment>
     */
    #[Computed]
    public function pendingAssignments(): Collection
    {
        return $this->workspace->assignments()
            ->with('execution.workflow')
            ->where('assignee_id', Auth::id())
            ->where('status', AssignmentStatus::Pending)
            ->oldest('due_at')
            ->get();
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

    @if ($this->pendingAssignments->isNotEmpty())
        <div class="mb-8">
            <div class="mb-2 flex items-center justify-between">
                <flux:heading>{{ __('common.waiting_on_you') }}</flux:heading>
                <flux:link :href="route('workspaces.inbox', $workspace)" wire:navigate class="text-sm">{{ __('common.inbox') }}</flux:link>
            </div>
            <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @foreach ($this->pendingAssignments as $assignment)
                    @include('partials.assignment-row', ['assignment' => $assignment])
                @endforeach
            </div>
        </div>
    @endif

    @if ($this->role?->can(WorkspacePermission::ViewWorkflows))
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ([
                ['label' => __('common.variables'), 'count' => $this->counts['variables'], 'route' => 'workspaces.variables', 'icon' => 'variable'],
                ['label' => __('common.secrets'), 'count' => $this->counts['secrets'], 'route' => 'workspaces.secrets', 'icon' => 'lock-closed'],
                ['label' => __('common.authentication'), 'count' => $this->counts['credentials'], 'route' => 'workspaces.credentials', 'icon' => 'key'],
                ['label' => __('common.members'), 'count' => $this->counts['members'], 'route' => 'workspaces.members', 'icon' => 'users'],
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
                <flux:heading>{{ __('common.workflows') }}</flux:heading>
                <flux:link :href="route('workspaces.workflows', $workspace)" wire:navigate class="text-sm">{{ __('common.all_workflows') }}</flux:link>
            </div>
            <div class="divide-y divide-zinc-200 rounded-xl border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
                @forelse ($workspace->workflows()->with('publishedVersion')->latest('updated_at')->limit(8)->get() as $workflow)
                    <a href="{{ route('workspaces.workflows.edit', [$workspace, $workflow]) }}" wire:navigate wire:key="wf-{{ $workflow->id }}"
                       class="flex items-center justify-between px-4 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-700/40">
                        <span class="font-medium">{{ $workflow->name }}</span>
                        @if ($workflow->publishedVersion)
                            <flux:badge size="sm" color="green">{{ __('common.version_short', ['n' => $workflow->publishedVersion->number]) }}</flux:badge>
                        @else
                            <flux:badge size="sm">{{ __('common.draft') }}</flux:badge>
                        @endif
                    </a>
                @empty
                    <div class="p-8 text-center text-sm text-zinc-500">{{ __('workspaces.overview.this_workspace_has_no_workflows') }}</div>
                @endforelse
            </div>
        </div>
    @elseif ($this->pendingAssignments->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
            <flux:heading>{{ __('workspaces.overview.your_approvals_and_tasks') }}</flux:heading>
            <flux:text class="mt-1">{{ __('workspaces.overview.approvals_and_tasks_assigned_to') }}</flux:text>
        </div>
    @endif
</section>
