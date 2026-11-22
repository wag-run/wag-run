<?php

use App\Models\Organization;
use App\Support\Audit;
use Flux\Flux;
use Livewire\Component;

new class extends Component {
    public string $name = '';

    public bool $registrationOpen = false;

    public bool $keepForever = false;

    public ?int $defaultRetentionDays = null;

    public function mount(): void
    {
        $organization = Organization::current();

        $this->name = $organization->name;
        $this->registrationOpen = $organization->registration_open;
        $this->keepForever = $organization->default_retention_days === null;
        $this->defaultRetentionDays = $organization->default_retention_days ?? 30;
    }

    public function save(): void
    {
        $this->authorize('install-admin');

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'defaultRetentionDays' => [$this->keepForever ? 'nullable' : 'required', 'integer', 'min:1', 'max:3650'],
        ]);

        $organization = Organization::current();
        $organization->fill([
            'name' => $this->name,
            'registration_open' => $this->registrationOpen,
            'default_retention_days' => $this->keepForever ? null : $this->defaultRetentionDays,
        ]);

        if ($organization->isDirty()) {
            $changed = array_keys($organization->getDirty());
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
    <x-page-heading :heading="__('common.sign_in_and_defaults')" :subheading="__('admin.settings.organization_wide_settings_workspaces_and')" />

    <form wire:submit="save" class="max-w-2xl space-y-10">
        <flux:input wire:model="name" :label="__('admin.settings.organization_name')" required />

        <div class="space-y-3">
            <flux:heading>{{ __('admin.settings.sign_in') }}</flux:heading>
            <flux:switch wire:model="registrationOpen" :label="__('admin.settings.allow_anyone_to_register')"
                :description="__('admin.settings.when_off_people_join_only')" />
            <flux:text class="text-sm">
                {{ __('admin.settings.people_can_also_sign_in') }}
                <flux:link :href="route('admin.sign-in-providers')" wire:navigate>{{ __('admin.settings.manage_sign_in_providers') }}</flux:link>
            </flux:text>
        </div>

        <div class="space-y-3">
            <flux:heading>{{ __('admin.settings.default_execution_log_retention') }}</flux:heading>
            <flux:switch wire:model.live="keepForever" :label="__('admin.settings.keep_execution_logs_forever')" />
            @unless ($keepForever)
                <flux:input wire:model="defaultRetentionDays" type="number" min="1" max="3650" :label="__('common.keep_logs_for_days')" class="max-w-48" />
            @endunless
        </div>

        <flux:button type="submit" variant="primary">{{ __('common.save_settings') }}</flux:button>
    </form>
</section>
