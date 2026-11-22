<?php

use App\Models\Secret;
use App\Models\Workspace;
use App\Support\Audit;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

new class extends Component {
    public Workspace $workspace;

    #[Locked]
    public ?int $editingId = null;

    public string $key = '';

    public string $value = '';

    public string $description = '';

    #[Computed]
    public function secrets()
    {
        return $this->workspace->secrets()->orderBy('key')->get();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('manage-secrets', $this->workspace);
    }

    #[Computed]
    public function canReveal(): bool
    {
        return Auth::user()->can('reveal-secrets', $this->workspace);
    }

    public function create(): void
    {
        $this->authorize('manage-secrets', $this->workspace);
        $this->resetForm();
        Flux::modal('secret-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('manage-secrets', $this->workspace);

        $secret = $this->workspace->secrets()->findOrFail($id);

        $this->resetForm();
        $this->editingId = $secret->id;
        $this->key = $secret->key;
        $this->description = (string) $secret->description;

        Flux::modal('secret-form')->show();
    }

    public function save(): void
    {
        $this->authorize('manage-secrets', $this->workspace);

        $validated = $this->validate([
            'key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/',
                Rule::unique('secrets', 'key')->where('workspace_id', $this->workspace->id)->ignore($this->editingId)],
            'value' => [$this->editingId ? 'nullable' : 'required', 'string', 'max:65000'],
            'description' => ['nullable', 'string', 'max:255'],
        ], ['key.regex' => __('Use letters, numbers and underscores, starting with a letter or underscore.')]);

        $secret = $this->editingId
            ? $this->workspace->secrets()->findOrFail($this->editingId)
            : new Secret;

        $secret->workspace_id = $this->workspace->id;
        $secret->key = $validated['key'];
        $secret->description = $validated['description'] ?: null;

        if (filled($validated['value'])) {
            $secret->value = $validated['value'];
        }

        $valueChanged = $secret->isDirty('value');
        $secret->save();

        Audit::record($this->editingId ? 'secret.updated' : 'secret.created', $secret, $this->workspace,
            $this->editingId ? ['value_changed' => $valueChanged] : []);

        Flux::modal('secret-form')->close();
        Flux::toast(variant: 'success', text: __('Secret saved.'));
        $this->resetForm();
    }

    /**
     * Return a secret's value to the browser for an admin, and audit the reveal.
     */
    #[Renderless]
    public function reveal(int $id): string
    {
        $this->authorize('reveal-secrets', $this->workspace);

        $secret = $this->workspace->secrets()->findOrFail($id);

        Audit::record('secret.revealed', $secret, $this->workspace);

        return $secret->value;
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-secrets', $this->workspace);

        $secret = $this->workspace->secrets()->findOrFail($id);
        $secret->delete();

        Audit::record('secret.deleted', $secret, $this->workspace);
        Flux::toast(text: __('Secret deleted.'));
    }

    protected function resetForm(): void
    {
        $this->reset('editingId', 'key', 'value', 'description');
        $this->resetValidation();
    }

    public function render()
    {
        return $this->view()->title(__('Secrets').' · '.$this->workspace->name);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('Secrets')" :subheading="__('Sensitive values for this workspace\'s workflows. Encrypted at rest and masked in execution logs.')">
        @if ($this->canManage)
            <x-slot:actions>
                <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Add secret') }}</flux:button>
            </x-slot:actions>
        @endif
    </x-page-heading>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Key') }}</flux:table.column>
            <flux:table.column>{{ __('Value') }}</flux:table.column>
            <flux:table.column>{{ __('Description') }}</flux:table.column>
            <flux:table.column>{{ __('Updated') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->secrets as $secret)
                <flux:table.row :key="$secret->id">
                    <flux:table.cell class="font-mono">{{ $secret->key }}</flux:table.cell>
                    <flux:table.cell>
                        <x-revealable-value :reveal="'reveal('.$secret->id.')'" :can-reveal="$this->canReveal" />
                    </flux:table.cell>
                    <flux:table.cell>{{ $secret->description }}</flux:table.cell>
                    <flux:table.cell class="whitespace-nowrap">{{ $secret->updated_at?->diffForHumans() }}</flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($this->canManage)
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $secret->id }})" :aria-label="__('Edit')" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $secret->id }})"
                                wire:confirm="{{ __('Delete this secret? Workflows that reference it will fail until it is replaced.') }}" :aria-label="__('Delete')" />
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('No secrets yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="secret-form" class="md:w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit secret') : __('Add secret') }}</flux:heading>
            <flux:input wire:model="key" :label="__('Key')" class="font-mono" placeholder="stripe_api_key" required />
            <flux:input wire:model="value" type="password" :label="__('Value')" viewable autocomplete="off"
                :description="$editingId ? __('Leave empty to keep the current value.') : null" />
            <flux:input wire:model="description" :label="__('Description')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
