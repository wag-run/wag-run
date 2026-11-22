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
        return $this->view()->title(__('Audit log').' · '.$this->workspace->name);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('Audit log')" :subheading="__('Changes to this workspace\'s workflows, secrets, authentication and membership, and every secret reveal.')" />

    <x-audit-log-table :entries="$this->entries" />
</section>
