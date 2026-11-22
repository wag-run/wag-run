<?php

use App\Models\Organization;
use App\Models\Workspace;
use App\Services\WorkspaceManager;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Workspaces')] class extends Component {
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    #[Computed]
    public function workspaces()
    {
        return Workspace::query()
            ->where('organization_id', Organization::current()?->id)
            ->withCount(['members', 'variables', 'secrets', 'credentials'])
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $this->authorize('install-admin');
        $this->reset('editingId', 'name');
        $this->resetValidation();
        Flux::modal('workspace-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('install-admin');

        $workspace = $this->findWorkspace($id);
        $this->editingId = $workspace->id;
        $this->name = $workspace->name;
        $this->resetValidation();

        Flux::modal('workspace-form')->show();
    }

    public function save(WorkspaceManager $workspaces): void
    {
        $this->authorize('install-admin');

        $this->validate(['name' => ['required', 'string', 'max:100']]);

        if ($this->editingId) {
            $workspaces->rename($this->findWorkspace($this->editingId), $this->name);
        } else {
            $workspaces->create(Organization::current(), $this->name);
        }

        Flux::modal('workspace-form')->close();
        Flux::toast(variant: 'success', text: __('Workspace saved.'));
        $this->reset('editingId', 'name');
    }

    public function delete(int $id, WorkspaceManager $workspaces): void
    {
        $this->authorize('install-admin');

        $workspaces->delete($this->findWorkspace($id));

        Flux::toast(text: __('Workspace deleted.'));
    }

    protected function findWorkspace(int $id): Workspace
    {
        return Workspace::query()->where('organization_id', Organization::current()?->id)->findOrFail($id);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('Workspaces')" :subheading="__('Each workspace keeps its own workflows, variables, secrets, authentication settings and members.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('New workspace') }}</flux:button>
        </x-slot:actions>
    </x-page-heading>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Members') }}</flux:table.column>
            <flux:table.column>{{ __('Variables') }}</flux:table.column>
            <flux:table.column>{{ __('Secrets') }}</flux:table.column>
            <flux:table.column>{{ __('Authentication') }}</flux:table.column>
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
                    <flux:table.cell>{{ $workspace->variables_count }}</flux:table.cell>
                    <flux:table.cell>{{ $workspace->secrets_count }}</flux:table.cell>
                    <flux:table.cell>{{ $workspace->credentials_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $workspace->id }})" :aria-label="__('Rename')" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $workspace->id }})"
                            wire:confirm="{{ __('Delete :name? Its workflows, execution logs, variables, secrets and authentication settings are deleted too. This cannot be undone.', ['name' => $workspace->name]) }}"
                            :aria-label="__('Delete')" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center text-zinc-500">{{ __('No workspaces yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="workspace-form" class="md:w-md">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Rename workspace') : __('New workspace') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Name')" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
