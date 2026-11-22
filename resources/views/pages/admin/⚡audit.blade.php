<?php

use App\Models\AuditLog;
use App\Models\Organization;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Audit log')] class extends Component {
    use WithPagination;

    #[Url]
    public string $action = '';

    #[Computed]
    public function entries()
    {
        return AuditLog::query()
            ->where('organization_id', Organization::current()?->id)
            ->when($this->action !== '', fn ($query) => $query->where('action', 'like', $this->action.'%'))
            ->with(['user', 'workspace'])
            ->latest('id')
            ->paginate(50);
    }

    public function updatedAction(): void
    {
        $this->resetPage();
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('Audit log')" :subheading="__('Every audited change on the install, across all workspaces.')">
        <x-slot:actions>
            <flux:select wire:model.live="action" class="w-48">
                <flux:select.option value="">{{ __('All actions') }}</flux:select.option>
                @foreach (['workspace' => __('Workspaces'), 'member' => __('Membership'), 'invitation' => __('Invitations'), 'secret' => __('Secrets'), 'credential' => __('Authentication'), 'variable' => __('Variables'), 'user' => __('Users'), 'install' => __('Install settings')] as $prefix => $label)
                    <flux:select.option :value="$prefix">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-slot:actions>
    </x-page-heading>

    <x-audit-log-table :entries="$this->entries" :show-workspace="true" />
</section>
