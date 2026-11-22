<?php

use App\Models\Workspace;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public Workspace $workspace;

    #[Computed]
    public function entries()
    {
        return $this->workspace->auditLogs()->with('user')->latest('id')->paginate(50);
    }

    public function render()
    {
        return $this->view()->title(__('common.audit_log').' · '.$this->workspace->name);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('common.audit_log')" :subheading="__('workspaces.audit.changes_to_this_workspace_s')" />

    <x-audit-log-table :entries="$this->entries" />
</section>
