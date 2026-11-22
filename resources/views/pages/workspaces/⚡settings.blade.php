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
            $this->addError('allowlist', __('Not a valid host, IP address or CIDR range: :entries', ['entries' => implode(', ', $invalid)]));

            return;
        }

        $workspaces->updateSettings($this->workspace, $this->customRetention ? $this->retentionDays : null, $entries);
        $this->allowlist = implode("\n", $entries);

        Flux::toast(variant: 'success', text: __('Workspace settings saved.'));
    }

    public function render()
    {
        return $this->view()->title(__('Settings').' · '.$this->workspace->name);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('Workspace settings')" :subheading="$workspace->name" />

    <form wire:submit="save" class="max-w-2xl space-y-10">
        <div class="space-y-4">
            <flux:heading>{{ __('Execution log retention') }}</flux:heading>
            <flux:text>{{ __('How long execution logs are kept, failed runs included. A workflow can override this.') }}</flux:text>

            <flux:switch wire:model.live="customRetention" :label="__('Use a workspace-specific retention period')" />

            @if ($customRetention)
                <flux:input wire:model="retentionDays" type="number" min="1" max="3650" :label="__('Keep logs for (days)')" class="max-w-48" />
            @else
                <flux:text class="text-sm">
                    {{ $workspace->organization->default_retention_days
                        ? __('Using the organization default: :days days.', ['days' => $workspace->organization->default_retention_days])
                        : __('Using the organization default: keep forever.') }}
                </flux:text>
            @endif
        </div>

        <div class="space-y-4">
            <flux:heading>{{ __('Internal-address allowlist') }}</flux:heading>
            <flux:text>
                {{ __('HTTP call nodes cannot reach private, loopback or link-local addresses unless they are listed here. One entry per line: a hostname, a wildcard like *.corp.internal, an IP address, or a CIDR range like 10.0.0.0/8.') }}
            </flux:text>
            <flux:textarea wire:model="allowlist" rows="6" class="font-mono" placeholder="10.0.0.0/8&#10;*.corp.internal&#10;192.168.1.20" />
            <flux:error name="allowlist" />
        </div>

        <flux:button type="submit" variant="primary">{{ __('Save settings') }}</flux:button>
    </form>
</section>
