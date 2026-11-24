<?php

use App\Enums\WorkspacePermission;
use App\Models\AuditLog;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public Workspace $workspace;

    /**
     * Empty for this workspace's entries. Organization admins may also pick
     * "organization": every entry in the organization, including those that
     * belong to no workspace (sign-ins, users, settings, deleted workspaces).
     */
    #[Locked]
    #[Url]
    public string $scope = '';

    #[Url]
    public string $action = '';

    /**
     * Workspaces whose audit log the user may read, for the dropdown.
     *
     * @return Collection<int, Workspace>
     */
    #[Computed]
    public function workspaces(): Collection
    {
        $user = Auth::user();

        return $user->accessibleWorkspaces()
            ->filter(fn (Workspace $workspace): bool => $user->hasWorkspacePermission($workspace, WorkspacePermission::ViewAuditLog))
            ->values();
    }

    #[Computed]
    public function canSeeOrganizationEntries(): bool
    {
        return Auth::user()->can('org-admin');
    }

    #[Computed]
    public function entries()
    {
        $query = AuditLog::query()->where('organization_id', $this->workspace->organization_id);

        return $query
            ->when($this->scope === '', fn ($query) => $query->where('workspace_id', $this->workspace->id))
            ->when($this->action !== '', fn ($query) => $query->where('action', 'like', $this->action.'%'))
            ->with(['user', 'workspace'])
            ->latest('id')
            ->paginate(50);
    }

    /**
     * Action prefixes the filter offers; organization-wide ones only in the
     * organization scope.
     *
     * @return array<string, string>
     */
    public function actionFilters(): array
    {
        $filters = [
            'workflow' => __('common.workflows'),
            'member' => __('workspaces.audit.membership'),
            'invitation' => __('workspaces.audit.invitations'),
            'secret' => __('common.secrets'),
            'credential' => __('common.credentials'),
            'variable' => __('common.variables'),
            'workspace' => __('common.workspaces'),
        ];

        if ($this->scope === '') {
            return $filters;
        }

        return [
            ...$filters,
            'user' => __('common.users'),
            'auth' => __('workspaces.audit.sign_in'),
            'oidc_provider' => __('common.sign_in_providers'),
            'organization' => __('workspaces.audit.organization_settings'),
        ];
    }

    public function scopeLabel(): string
    {
        return $this->scope === 'organization' ? __('navigation.organization') : $this->workspace->name;
    }

    public function updatedAction(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        abort_unless(in_array($this->scope, ['', 'organization'], true), 404);
        abort_if($this->scope !== '' && ! $this->canSeeOrganizationEntries, 403);

        return $this->view()->title(__('common.audit_log').' · '.$this->scopeLabel());
    }
}; ?>

<section class="w-full">
    <x-page-heading
        :heading="__('common.audit_log')"
        :subheading="$scope === 'organization' ? __('workspaces.audit.every_audited_change_in_the') : __('workspaces.audit.changes_to_this_workspace_s')"
    />

    <div class="mb-4 flex flex-wrap gap-3">
        <flux:dropdown position="bottom" align="start">
            <flux:button icon:trailing="chevron-down" :aria-label="__('workspaces.audit.show_log_for')" data-test="audit-scope">
                {{ $this->scopeLabel() }}
            </flux:button>

            <flux:menu>
                <flux:menu.heading>{{ __('common.workspaces') }}</flux:menu.heading>
                @foreach ($this->workspaces as $option)
                    <flux:menu.item
                        wire:key="audit-workspace-{{ $option->id }}"
                        :href="route('workspaces.audit', [$option, ...array_filter(['action' => $action])])"
                        :icon="$scope === '' && $workspace->is($option) ? 'check' : null"
                        wire:navigate
                    >
                        {{ $option->name }}
                    </flux:menu.item>
                @endforeach

                @if ($this->canSeeOrganizationEntries)
                    <flux:menu.separator />
                    <flux:menu.item
                        :href="route('workspaces.audit', [$workspace, 'scope' => 'organization', ...array_filter(['action' => $action])])"
                        :icon="$scope === 'organization' ? 'check' : null"
                        wire:navigate
                    >
                        {{ __('navigation.organization') }}
                    </flux:menu.item>
                @endif
            </flux:menu>
        </flux:dropdown>

        <flux:select wire:model.live="action" class="w-48" :aria-label="__('ui.action')">
            <flux:select.option value="">{{ __('workspaces.audit.all_actions') }}</flux:select.option>
            @foreach ($this->actionFilters() as $prefix => $label)
                <flux:select.option :value="$prefix">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <x-audit-log-table :entries="$this->entries" :show-workspace="$scope !== ''" />
</section>
