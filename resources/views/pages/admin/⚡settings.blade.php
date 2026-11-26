<?php

use App\Models\Organization;
use App\Support\Audit;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public string $name = '';

    public bool $keepForever = false;

    public ?int $defaultRetentionDays = null;

    public bool $allowAccountDeletion = true;

    public int|string $maxChainDepth = 10;

    public int|string $maxRunsPerMinute = 60;

    public function mount(): void
    {
        $organization = Organization::current();

        $this->name = $organization->name;
        $this->keepForever = $organization->default_retention_days === null;
        $this->defaultRetentionDays = $organization->default_retention_days ?? 30;
        $this->allowAccountDeletion = $organization->allow_account_deletion;
        $this->maxChainDepth = $organization->max_chain_depth;
        $this->maxRunsPerMinute = $organization->max_runs_per_minute;
    }

    public function save(): void
    {
        $this->authorize('org-admin');

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'defaultRetentionDays' => [$this->keepForever ? 'nullable' : 'required', 'integer', 'min:1', 'max:3650'],
            'maxChainDepth' => ['required', 'integer', 'min:1', 'max:100'],
            'maxRunsPerMinute' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);

        $organization = Organization::current();
        $organization->fill([
            'name' => $this->name,
            'default_retention_days' => $this->keepForever ? null : $this->defaultRetentionDays,
            'allow_account_deletion' => $this->allowAccountDeletion,
            'max_chain_depth' => (int) $this->maxChainDepth,
            'max_runs_per_minute' => (int) $this->maxRunsPerMinute,
        ]);

        if ($organization->isDirty()) {
            $changed = $organization->changedFields();
            $organization->save();
            Audit::record('organization.settings_updated', $organization, metadata: ['changed' => $changed]);
        }

        Flux::toast(variant: 'success', text: __('admin.settings.organization_settings_saved'));
    }

    public function render()
    {
        return $this->view()->title(__('admin.settings.title'));
    }
}; ?>

<section class="w-full">
    <x-pages::admin.layout :heading="__('admin.settings.title')" :subheading="__('admin.settings.organization_wide_settings_workspaces_and')">
        <form wire:submit="save" class="max-w-2xl space-y-10">
            <flux:input wire:model="name" :label="__('admin.settings.organization_name')" required />

            <div class="space-y-3">
                <flux:heading>{{ __('admin.settings.accounts') }}</flux:heading>
                <flux:switch wire:model="allowAccountDeletion" :label="__('admin.settings.let_people_delete_their_accounts')"
                    :description="__('admin.settings.when_off_the_delete_account')" data-test="allow-account-deletion" />
            </div>

            <div class="space-y-3">
                <flux:heading>{{ __('admin.settings.default_execution_log_retention') }}</flux:heading>
                <flux:switch wire:model.live="keepForever" :label="__('admin.settings.keep_execution_logs_forever')" />
                @unless ($keepForever)
                    <flux:input wire:model="defaultRetentionDays" type="number" min="1" max="3650" :label="__('common.keep_logs_for_days')" class="max-w-48" />
                @endunless
            </div>

            <div class="space-y-3">
                <flux:heading>{{ __('admin.settings.workflow_chains') }}</flux:heading>
                <flux:input wire:model="maxChainDepth" type="number" min="1" max="100" :label="__('admin.settings.maximum_chain_depth')"
                    :description="__('admin.settings.how_many_runs_deep_a')" class="max-w-48" data-test="max-chain-depth" />
                <flux:input wire:model="maxRunsPerMinute" type="number" min="1" max="10000" :label="__('admin.settings.maximum_runs_per_minute')"
                    :description="__('admin.settings.how_many_runs_one_workflow')" class="max-w-48" data-test="max-runs-per-minute" />
            </div>

            <flux:button type="submit" variant="primary">{{ __('common.save_settings') }}</flux:button>
        </form>
    </x-pages::admin.layout>
</section>
