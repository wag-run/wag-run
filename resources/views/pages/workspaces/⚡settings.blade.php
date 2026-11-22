<?php

use App\Models\Workspace;
use App\Services\WorkspaceManager;
use App\Support\AddressAllowlist;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public Workspace $workspace;

    public bool $customRetention = false;

    public ?int $retentionDays = null;

    public string $allowlist = '';

    public function mount(): void
    {
        $this->customRetention = $this->workspace->retention_days !== null;
        $this->retentionDays = $this->workspace->retention_days ?? $this->workspace->organization->default_retention_days;
        $this->allowlist = implode("\n", $this->workspace->internal_allowlist ?? []);
    }

    public function save(WorkspaceManager $workspaces): void
    {
        $this->authorize('manage-settings', $this->workspace);

        $this->validate([
            'retentionDays' => [$this->customRetention ? 'required' : 'nullable', 'integer', 'min:1', 'max:3650'],
            'allowlist' => ['nullable', 'string', 'max:10000'],
        ]);

        $entries = AddressAllowlist::parse($this->allowlist);
        $invalid = array_values(array_filter($entries, fn (string $entry) => ! AddressAllowlist::isValidEntry($entry)));

        if ($invalid !== []) {
            $this->addError('allowlist', __('workspaces.settings.not_a_valid_host_ip', ['entries' => implode(', ', $invalid)]));

            return;
        }

        $workspaces->updateSettings($this->workspace, $this->customRetention ? $this->retentionDays : null, $entries);
        $this->allowlist = implode("\n", $entries);

        Flux::toast(variant: 'success', text: __('workspaces.settings.workspace_settings_saved'));
    }

    public function render()
    {
        return $this->view()->title(__('common.settings').' · '.$this->workspace->name);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('workspaces.settings.workspace_settings')" :subheading="$workspace->name" />

    <form wire:submit="save" class="max-w-2xl space-y-10">
        <div class="space-y-4">
            <flux:heading>{{ __('workspaces.settings.execution_log_retention') }}</flux:heading>
            <flux:text>{{ __('workspaces.settings.how_long_execution_logs_are') }}</flux:text>

            <flux:switch wire:model.live="customRetention" :label="__('workspaces.settings.use_a_workspace_specific_retention')" />

            @if ($customRetention)
                <flux:input wire:model="retentionDays" type="number" min="1" max="3650" :label="__('common.keep_logs_for_days')" class="max-w-48" />
            @else
                <flux:text class="text-sm">
                    {{ $workspace->organization->default_retention_days
                        ? __('workspaces.settings.using_the_organization_default_days', ['days' => $workspace->organization->default_retention_days])
                        : __('workspaces.settings.using_the_organization_default_keep') }}
                </flux:text>
            @endif
        </div>

        <div class="space-y-4">
            <flux:heading>{{ __('workspaces.settings.internal_address_allowlist') }}</flux:heading>
            <flux:text>
                {{ __('workspaces.settings.http_call_nodes_cannot_reach') }}
            </flux:text>
            <flux:textarea wire:model="allowlist" rows="6" class="font-mono" placeholder="10.0.0.0/8&#10;*.corp.internal&#10;192.168.1.20" />
            <flux:error name="allowlist" />
        </div>

        <flux:button type="submit" variant="primary">{{ __('common.save_settings') }}</flux:button>
    </form>
</section>
