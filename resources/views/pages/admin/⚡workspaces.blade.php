<?php

use App\Models\Organization;
use App\Models\Workspace;
use App\Services\WorkspaceManager;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    #[Computed]
    public function workspaces()
    {
        return Workspace::query()
            ->where('organization_id', Organization::current()?->id)
            ->withCount(['members', 'storeEntries', 'secrets', 'credentials'])
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->authorize('org-admin');
        $this->reset('editingId', 'name');
        $this->resetValidation();
        Flux::modal('workspace-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('org-admin');

        $workspace = $this->findWorkspace($id);
        $this->editingId = $workspace->id;
        $this->name = $workspace->name;
        $this->resetValidation();

        Flux::modal('workspace-form')->show();
    }

    public function save(WorkspaceManager $workspaces): void
    {
        $this->authorize('org-admin');

        $this->validate(['name' => ['required', 'string', 'max:100']]);

        if ($this->editingId) {
            $workspaces->rename($this->findWorkspace($this->editingId), $this->name);
        } else {
            $workspaces->create(Organization::current(), $this->name);
        }

        Flux::modal('workspace-form')->close();
        Flux::toast(variant: 'success', text: __('admin.workspaces.workspace_saved'));
        $this->reset('editingId', 'name');
    }

    public function delete(int $id, WorkspaceManager $workspaces): void
    {
        $this->authorize('org-admin');

        $workspaces->delete($this->findWorkspace($id));

        Flux::toast(text: __('admin.workspaces.workspace_deleted'));
    }

    protected function findWorkspace(int $id): Workspace
    {
        return Workspace::query()->where('organization_id', Organization::current()?->id)->findOrFail($id);
    }

    public function render()
    {
        return $this->view()->title(__('admin.workspaces.title'));
    }
}; ?>

<section class="w-full">
    <x-pages::admin.layout :heading="__('common.workspaces')" :subheading="__('admin.workspaces.each_workspace_keeps_its_own')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('admin.workspaces.new_workspace') }}</flux:button>
        </x-slot:actions>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('common.name') }}</flux:table.column>
                <flux:table.column>{{ __('common.members') }}</flux:table.column>
                <flux:table.column>{{ __('admin.workspaces.data_store_entries') }}</flux:table.column>
                <flux:table.column>{{ __('common.secrets') }}</flux:table.column>
                <flux:table.column>{{ __('common.credentials') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->workspaces as $workspace)
                    <flux:table.row :key="$workspace->id">
                        <flux:table.cell>
                            <flux:link :href="route('workspaces.show', $workspace)" wire:navigate>{{ $workspace->name }}</flux:link>
                            <div class="font-mono text-xs text-zinc-500">{{ $workspace->slug }}</div>
                        </flux:table.cell>
                        <flux:table.cell>{{ $workspace->members_count }}</flux:table.cell>
                        <flux:table.cell>{{ $workspace->store_entries_count }}</flux:table.cell>
                        <flux:table.cell>{{ $workspace->secrets_count }}</flux:table.cell>
                        <flux:table.cell>{{ $workspace->credentials_count }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $workspace->id }})" :aria-label="__('admin.workspaces.rename')" />
                            <flux:button size="sm" variant="ghost" icon="trash" x-on:click="$confirm({{ \Illuminate\Support\Js::from(['title' => __('admin.workspaces.delete_workspace'), 'message' => __('admin.workspaces.delete_name_its_workflows_execution', ['name' => $workspace->name]), 'confirm' => __('common.delete')]) }}).then((ok) => ok && $wire.delete({{ $workspace->id }}))"
                                :aria-label="__('common.delete')" />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="6" class="text-center text-zinc-500">{{ __('admin.workspaces.no_workspaces_yet') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <flux:modal name="workspace-form" class="md:w-md">
            <form wire:submit="save" class="space-y-6">
                <flux:heading size="lg">{{ $editingId ? __('admin.workspaces.rename_workspace') : __('admin.workspaces.new_workspace') }}</flux:heading>
                <flux:input wire:model="name" :label="__('common.name')" required autofocus data-test="workspace-name" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('common.cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('common.save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    </x-pages::admin.layout>
</section>
