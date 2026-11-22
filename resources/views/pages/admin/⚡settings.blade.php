<?php

use App\Models\Organization;
use App\Support\Audit;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sign-in & defaults')] class extends Component {
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

        Flux::toast(variant: 'success', text: __('Organization settings saved.'));
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('Sign-in & defaults')" :subheading="__('Organization-wide settings. Workspaces and workflows can override the defaults.')" />

    <form wire:submit="save" class="max-w-2xl space-y-10">
        <flux:input wire:model="name" :label="__('Organization name')" required />

        <div class="space-y-3">
            <flux:heading>{{ __('Sign-in') }}</flux:heading>
            <flux:switch wire:model="registrationOpen" :label="__('Allow anyone to register')"
                :description="__('When off, people join only by invitation. New accounts join as organization Members with no workspace access.')" />
            <flux:text class="text-sm">
                {{ __('People can also sign in with an OpenID Connect provider.') }}
                <flux:link :href="route('admin.sign-in-providers')" wire:navigate>{{ __('Manage sign-in providers') }}</flux:link>
            </flux:text>
        </div>

        <div class="space-y-3">
            <flux:heading>{{ __('Default execution log retention') }}</flux:heading>
            <flux:switch wire:model.live="keepForever" :label="__('Keep execution logs forever')" />
            @unless ($keepForever)
                <flux:input wire:model="defaultRetentionDays" type="number" min="1" max="3650" :label="__('Keep logs for (days)')" class="max-w-48" />
            @endunless
        </div>

        <flux:button type="submit" variant="primary">{{ __('Save settings') }}</flux:button>
    </form>
</section>
