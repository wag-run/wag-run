<?php

use App\Models\Variable;
use App\Models\Workspace;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    public Workspace $workspace;

    #[Locked]
    public ?int $editingId = null;

    public string $key = '';

    public string $value = '';

    public string $description = '';

    #[Computed]
    public function variables()
    {
        return $this->workspace->variables()->orderBy('key')->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('manage-variables', $this->workspace);
    }

    public function create(): void
    {
        $this->authorize('manage-variables', $this->workspace);
        $this->resetForm();
        Flux::modal('variable-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('manage-variables', $this->workspace);

        $variable = $this->workspace->variables()->findOrFail($id);

        $this->editingId = $variable->id;
        $this->key = $variable->key;
        $this->value = (string) $variable->value;
        $this->description = (string) $variable->description;

        Flux::modal('variable-form')->show();
    }

    public function save(): void
    {
        $this->authorize('manage-variables', $this->workspace);

        $validated = $this->validate([
            'key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/',
                Rule::unique('variables', 'key')->where('workspace_id', $this->workspace->id)->ignore($this->editingId)],
            'value' => ['nullable', 'string', 'max:65000'],
            'description' => ['nullable', 'string', 'max:255'],
        ], ['key.regex' => __('common.use_letters_numbers_and_underscores')]);

        $variable = $this->editingId
            ? $this->workspace->variables()->findOrFail($this->editingId)
            : new Variable(['workspace_id' => $this->workspace->id]);

        $variable->workspace_id = $this->workspace->id;
        $variable->fill($validated)->save();

        Audit::record($this->editingId ? 'variable.updated' : 'variable.created', $variable, $this->workspace);

        Flux::modal('variable-form')->close();
        Flux::toast(variant: 'success', text: __('workspaces.variables.variable_saved'));
        $this->resetForm();
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-variables', $this->workspace);

        $variable = $this->workspace->variables()->findOrFail($id);
        $variable->delete();

        Audit::record('variable.deleted', $variable, $this->workspace);
        Flux::toast(text: __('workspaces.variables.variable_deleted'));
    }

    protected function resetForm(): void
    {
        $this->reset('editingId', 'key', 'value', 'description');
        $this->resetValidation();
    }

    public function render()
    {
        return $this->view()->title(__('common.variables').' · '.$this->workspace->name);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('common.variables')" :subheading="__('workspaces.variables.named_values_every_workflow_in')">
        @if ($this->canManage)
            <x-slot:actions>
                <flux:button variant="primary" icon="plus" wire:click="create">{{ __('workspaces.variables.add_variable') }}</flux:button>
            </x-slot:actions>
        @endif
    </x-page-heading>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('common.key') }}</flux:table.column>
            <flux:table.column>{{ __('common.value') }}</flux:table.column>
            <flux:table.column>{{ __('common.description') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->variables as $variable)
                <flux:table.row :key="$variable->id">
                    <flux:table.cell class="font-mono">{{ $variable->key }}</flux:table.cell>
                    <flux:table.cell class="max-w-xs truncate font-mono">{{ $variable->value }}</flux:table.cell>
                    <flux:table.cell>{{ $variable->description }}</flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($this->canManage)
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $variable->id }})" :aria-label="__('common.edit')" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $variable->id }})"
                                wire:confirm="{{ __('workspaces.variables.delete_this_variable_workflows_that') }}" :aria-label="__('common.delete')" />
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="4" class="text-center text-zinc-500">{{ __('workspaces.variables.no_variables_yet') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="variable-form" class="md:w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('workspaces.variables.edit_variable') : __('workspaces.variables.add_variable') }}</flux:heading>
            <flux:input wire:model="key" :label="__('common.key')" class="font-mono" placeholder="api_base_url" required />
            <flux:textarea wire:model="value" :label="__('common.value')" rows="3" />
            <flux:input wire:model="description" :label="__('common.description')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('common.cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('common.save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
