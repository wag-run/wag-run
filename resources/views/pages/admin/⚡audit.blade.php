<?php

use App\Models\AuditLog;
use App\Models\Organization;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
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

    public function render()
    {
        return $this->view()->title(__('admin.audit.title'));
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('common.audit_log')" :subheading="__('admin.audit.every_audited_change_in_the')">
        <x-slot:actions>
            <flux:select wire:model.live="action" class="w-48">
                <flux:select.option value="">{{ __('admin.audit.all_actions') }}</flux:select.option>
                @foreach (['workspace' => __('common.workspaces'), 'member' => __('admin.audit.membership'), 'invitation' => __('admin.audit.invitations'), 'secret' => __('common.secrets'), 'credential' => __('common.authentication'), 'variable' => __('common.variables'), 'user' => __('common.users'), 'organization' => __('admin.audit.organization_settings')] as $prefix => $label)
                    <flux:select.option :value="$prefix">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
        </x-slot:actions>
    </x-page-heading>

    <x-audit-log-table :entries="$this->entries" :show-workspace="true" />
</section>
