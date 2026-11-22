<?php

use App\Credentials\CredentialType;
use App\Credentials\CredentialTypeRegistry;
use App\Models\Credential;
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

    public string $type = 'api_token';

    public string $description = '';

    /**
     * Non-secret fields hold their current values. Secret fields start empty
     * and are only written when filled in.
     *
     * @var array<string, string|null>
     */
    public array $config = [];

    public function mount(): void
    {
        $this->config = $this->selectedType()->defaults();
    }

    #[Computed]
    public function credentials()
    {
        return $this->workspace->credentials()->orderBy('key')->get();
    }

    /**
     * @return array<string, CredentialType>
     */
    #[Computed]
    public function types(): array
    {
        return app(CredentialTypeRegistry::class)->all();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()->can('manage-credentials', $this->workspace);
    }

    public function selectedType(): CredentialType
    {
        $registry = app(CredentialTypeRegistry::class);

        return $registry->has($this->type) ? $registry->get($this->type) : $registry->get('api_token');
    }

    public function updatedType(): void
    {
        $this->config = $this->selectedType()->defaults();
        $this->resetValidation();
    }

    public function create(): void
    {
        $this->authorize('manage-credentials', $this->workspace);
        $this->resetForm();
        Flux::modal('credential-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('manage-credentials', $this->workspace);

        $credential = $this->workspace->credentials()->findOrFail($id);
        $type = $credential->credentialType();

        $this->resetForm();
        $this->editingId = $credential->id;
        $this->key = $credential->key;
        $this->type = $credential->type;
        $this->description = (string) $credential->description;
        $this->config = collect($type->defaults())
            ->mapWithKeys(fn ($default, $name) => [$name => in_array($name, $type->secretFieldNames(), true) ? '' : ($credential->config[$name] ?? $default)])
            ->all();

        Flux::modal('credential-form')->show();
    }

    public function save(): void
    {
        $this->authorize('manage-credentials', $this->workspace);

        $registry = app(CredentialTypeRegistry::class);
        $existing = $this->editingId ? $this->workspace->credentials()->findOrFail($this->editingId) : null;

        $this->validate([
            'key' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/',
                Rule::unique('credentials', 'key')->where('workspace_id', $this->workspace->id)->ignore($this->editingId)],
            'type' => ['required', Rule::in(array_keys($registry->all()))],
            'description' => ['nullable', 'string', 'max:255'],
        ], ['key.regex' => __('Use letters, numbers and underscores, starting with a letter or underscore.')]);

        $type = $registry->get($this->type);
        $secretFields = $type->secretFieldNames();
        $keepingSecrets = $existing && $existing->type === $type->key();

        $rules = $type->rules();
        if ($keepingSecrets) {
            foreach ($secretFields as $name) {
                $rules["config.{$name}"] = ['nullable', 'string', 'max:4000'];
            }
        }
        $this->validate($rules, [], collect($type->fields())->mapWithKeys(fn ($f) => ["config.{$f->name}" => $f->label])->all());

        $config = [];
        foreach ($type->fields() as $field) {
            $value = $this->config[$field->name] ?? null;
            $config[$field->name] = $field->secret && blank($value) && $keepingSecrets
                ? ($existing->config[$field->name] ?? null)
                : (filled($value) ? $value : null);
        }

        $credential = $existing ?? new Credential;
        $credential->workspace_id = $this->workspace->id;
        $credential->fill([
            'key' => $this->key,
            'type' => $type->key(),
            'description' => $this->description ?: null,
            'config' => $config,
        ])->save();

        Audit::record($existing ? 'credential.updated' : 'credential.created', $credential, $this->workspace, ['type' => $type->key()]);

        Flux::modal('credential-form')->close();
        Flux::toast(variant: 'success', text: __('Authentication setting saved.'));
        $this->resetForm();
    }

    /**
     * Return one secret field of a setting to the browser, and audit the reveal.
     */
    #[Renderless]
    public function reveal(int $id, string $field): ?string
    {
        $this->authorize('reveal-secrets', $this->workspace);

        $credential = $this->workspace->credentials()->findOrFail($id);

        abort_unless(in_array($field, $credential->credentialType()->secretFieldNames(), true), 404);

        Audit::record('credential.revealed', $credential, $this->workspace, ['field' => $field]);

        return $credential->config[$field] ?? null;
    }

    public function delete(int $id): void
    {
        $this->authorize('manage-credentials', $this->workspace);

        $credential = $this->workspace->credentials()->findOrFail($id);
        $credential->delete();

        Audit::record('credential.deleted', $credential, $this->workspace);
        Flux::toast(text: __('Authentication setting deleted.'));
    }

    protected function resetForm(): void
    {
        $this->reset('editingId', 'key', 'type', 'description');
        $this->config = $this->selectedType()->defaults();
        $this->resetValidation();
    }

    public function render()
    {
        return $this->view()->title(__('Authentication').' · '.$this->workspace->name);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('Authentication')" :subheading="__('Reusable credentials this workspace\'s workflows use to call other systems.')">
        @if ($this->canManage)
            <x-slot:actions>
                <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Add authentication') }}</flux:button>
            </x-slot:actions>
        @endif
    </x-page-heading>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Key') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Settings') }}</flux:table.column>
            <flux:table.column>{{ __('Description') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->credentials as $credential)
                @php($credentialType = $credential->credentialType())
                <flux:table.row :key="$credential->id">
                    <flux:table.cell class="font-mono">{{ $credential->key }}</flux:table.cell>
                    <flux:table.cell><flux:badge size="sm">{{ $credentialType->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell>
                        <dl class="grid grid-cols-[auto_1fr] items-center gap-x-3 gap-y-1 text-sm">
                            @foreach ($credentialType->fields() as $field)
                                <dt class="text-zinc-500">{{ $field->label }}</dt>
                                <dd>
                                    @if ($field->secret)
                                        @if ($this->canManage)
                                            <x-revealable-value :reveal="'reveal('.$credential->id.', \''.$field->name.'\')'" :can-reveal="true" />
                                        @else
                                            <span class="font-mono">••••••••••••</span>
                                        @endif
                                    @elseif ($field->type === 'select')
                                        {{ $field->options[$credential->config[$field->name] ?? ''] ?? '—' }}
                                    @else
                                        <span class="font-mono">{{ $credential->config[$field->name] ?? '—' }}</span>
                                    @endif
                                </dd>
                            @endforeach
                        </dl>
                    </flux:table.cell>
                    <flux:table.cell>{{ $credential->description }}</flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($this->canManage)
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $credential->id }})" :aria-label="__('Edit')" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $credential->id }})"
                                wire:confirm="{{ __('Delete this authentication setting? Workflows that use it will fail until it is replaced.') }}" :aria-label="__('Delete')" />
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('No authentication settings yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="credential-form" class="md:w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit authentication') : __('Add authentication') }}</flux:heading>

            <flux:select wire:model.live="type" :label="__('Type')" :disabled="(bool) $editingId">
                @foreach ($this->types as $typeKey => $credentialType)
                    <flux:select.option :value="$typeKey" wire:key="type-{{ $typeKey }}">{{ $credentialType->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text class="-mt-4 text-sm">{{ $this->selectedType()->description() }}</flux:text>

            <flux:input wire:model="key" :label="__('Key')" class="font-mono" placeholder="github_api" required />

            @foreach ($this->selectedType()->fields() as $field)
                <div wire:key="field-{{ $type }}-{{ $field->name }}">
                    @if ($field->type === 'select')
                        <flux:select wire:model="config.{{ $field->name }}" :label="$field->label">
                            @foreach ($field->options as $optionValue => $optionLabel)
                                <flux:select.option :value="$optionValue">{{ $optionLabel }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @elseif ($field->secret)
                        <flux:input wire:model="config.{{ $field->name }}" type="password" viewable autocomplete="off" :label="$field->label"
                            :description="$editingId ? __('Leave empty to keep the current value.') : $field->help" />
                    @else
                        <flux:input wire:model="config.{{ $field->name }}" :type="$field->type === 'url' ? 'url' : 'text'" :label="$field->label" :description="$field->help" />
                    @endif
                </div>
            @endforeach

            <flux:input wire:model="description" :label="__('Description')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
