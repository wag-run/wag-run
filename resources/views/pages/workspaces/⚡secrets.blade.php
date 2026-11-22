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
        ], ['key.regex' => __('common.use_letters_numbers_and_underscores')]);

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
        Flux::toast(variant: 'success', text: __('workspaces.secrets.secret_saved'));
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
        Flux::toast(text: __('workspaces.secrets.secret_deleted'));
    }

    protected function resetForm(): void
    {
        $this->reset('editingId', 'key', 'value', 'description');
        $this->resetValidation();
    }

    public function render()
    {
        return $this->view()->title(__('common.secrets').' · '.$this->workspace->name);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('common.secrets')" :subheading="__('workspaces.secrets.sensitive_values_for_this_workspace')">
        @if ($this->canManage)
            <x-slot:actions>
                <flux:button variant="primary" icon="plus" wire:click="create">{{ __('workspaces.secrets.add_secret') }}</flux:button>
            </x-slot:actions>
        @endif
    </x-page-heading>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('common.key') }}</flux:table.column>
            <flux:table.column>{{ __('common.value') }}</flux:table.column>
            <flux:table.column>{{ __('common.description') }}</flux:table.column>
            <flux:table.column>{{ __('workspaces.secrets.updated') }}</flux:table.column>
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
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $secret->id }})" :aria-label="__('common.edit')" />
                            <flux:button size="sm" variant="ghost" icon="trash" x-on:click="$confirm({{ \Illuminate\Support\Js::from(['title' => __('workspaces.secrets.delete_secret'), 'message' => __('workspaces.secrets.delete_this_secret_workflows_that'), 'confirm' => __('common.delete')]) }}).then((ok) => ok && $wire.delete({{ $secret->id }}))" :aria-label="__('common.delete')" />
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('workspaces.secrets.no_secrets_yet') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="secret-form" class="md:w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('workspaces.secrets.edit_secret') : __('workspaces.secrets.add_secret') }}</flux:heading>
            <flux:input wire:model="key" :label="__('common.key')" class="font-mono" placeholder="stripe_api_key" required />
            <flux:input wire:model="value" type="password" :label="__('common.value')" viewable autocomplete="off"
                :description="$editingId ? __('common.leave_empty_to_keep_the') : null" />
            <flux:input wire:model="description" :label="__('common.description')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('common.cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('common.save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
