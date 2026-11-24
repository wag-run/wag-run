<?php

use App\Credentials\CredentialType;
use App\Credentials\OAuthCredentialType;
use App\Credentials\CredentialTypeRegistry;
use App\Models\Credential;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Services\OAuthConnections;
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
    public ?string $editingId = null;

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
        return $this->workspace->credentials()->orderBy('description')->get();
    }

    /**
     * The workspace's workflows that use each credential, in the draft or
     * the published version, keyed by credential ID.
     *
     * @return array<string, list<Workflow>>
     */
    #[Computed]
    public function workflowsByCredential(): array
    {
        $usage = [];

        foreach ($this->workspace->workflows()->with('publishedVersion')->orderBy('name')->get() as $workflow) {
            foreach ($workflow->credentialIds() as $credentialId) {
                $usage[$credentialId][] = $workflow;
            }
        }

        return $usage;
    }

    /**
     * @return array<string, CredentialType>
     */
    #[Computed]
    public function types(): array
    {
        return app(CredentialTypeRegistry::class)->all();
    }

    /**
     * Whether the form connects a new account instead of asking for
     * details: adding a provider whose app is set up in the environment,
     * such as GitHub.
     */
    public function connectsNewAccount(): bool
    {
        $type = $this->selectedType();

        return ! $this->editingId && $type instanceof OAuthCredentialType && $type->connectsWithoutDetails();
    }

    /**
     * Connect a new account of the chosen type: off to the provider, and
     * back to this page with the new setting listed.
     */
    public function connect(OAuthConnections $connections): void
    {
        $this->authorize('manage-credentials', $this->workspace);

        $type = $this->selectedType();
        abort_unless($this->connectsNewAccount() && $type instanceof OAuthCredentialType && $type->isConfigured(), 404);

        $this->redirect($connections->beginForWorkspace($type, $this->workspace, Auth::user()));
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

    public function edit(string $id): void
    {
        $this->authorize('manage-credentials', $this->workspace);

        $credential = $this->workspace->credentials()->findOrFail($id);
        $type = $credential->credentialType();

        $this->resetForm();
        $this->editingId = $credential->id;
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
            'type' => ['required', Rule::in($existing ? [$existing->type] : array_keys(array_filter($registry->all(), fn (CredentialType $type) => ! ($type instanceof OAuthCredentialType && $type->connectsWithoutDetails()))))],
            'description' => ['required', 'string', 'max:255',
                Rule::unique('credentials', 'description')->where('workspace_id', $this->workspace->id)->ignore($this->editingId)],
        ], ['description.unique' => __('workspaces.credentials.another_setting_has_this_description')]);

        $type = $registry->get($this->type);
        $keepingSecrets = $existing && $existing->type === $type->key();

        $rules = $type->rules();
        if ($keepingSecrets) {
            foreach ($type->fields() as $field) {
                if ($field->secret) {
                    $rules["config.{$field->name}"] = ['nullable', ...array_slice($field->rules(), 1)];
                }
            }
        }
        if ($rules !== []) {
            $this->validate($rules, [], collect($type->fields())->mapWithKeys(fn ($f) => ["config.{$f->name}" => $f->label])->all());
        }

        $config = $keepingSecrets ? $type->keptOnSave($existing->config) : [];
        foreach ($type->fields() as $field) {
            $value = $this->config[$field->name] ?? null;
            $config[$field->name] = $field->secret && blank($value) && $keepingSecrets
                ? ($existing->config[$field->name] ?? null)
                : (filled($value) ? $value : null);
        }

        $credential = $existing ?? new Credential;
        $credential->workspace_id = $this->workspace->id;
        $credential->fill([
            'type' => $type->key(),
            'description' => trim($this->description),
            'config' => $config,
        ])->save();

        Audit::record($existing ? 'credential.updated' : 'credential.created', $credential, $this->workspace, ['type' => $type->key()]);

        if ($type instanceof OAuthCredentialType && ! OAuthCredentialType::isConnected($credential)) {
            $this->redirectRoute('workspaces.credentials.connect', [$this->workspace, $credential]);

            return;
        }

        Flux::modal('credential-form')->close();
        Flux::toast(variant: 'success', text: __('workspaces.credentials.credential_saved'));
        $this->resetForm();
    }

    /**
     * Return one secret field of a setting to the browser, and audit the reveal.
     */
    #[Renderless]
    public function reveal(string $id, string $field): ?string
    {
        $this->authorize('reveal-secrets', $this->workspace);

        $credential = $this->workspace->credentials()->findOrFail($id);

        abort_unless(in_array($field, $credential->credentialType()->secretFieldNames(), true), 404);

        Audit::record('credential.revealed', $credential, $this->workspace, ['field' => $field]);

        return $credential->config[$field] ?? null;
    }

    public function delete(string $id): void
    {
        $this->authorize('manage-credentials', $this->workspace);

        $credential = $this->workspace->credentials()->findOrFail($id);
        $credential->delete();

        Audit::record('credential.deleted', $credential, $this->workspace);
        Flux::toast(text: __('workspaces.credentials.credential_deleted'));
    }

    protected function resetForm(): void
    {
        $this->reset('editingId', 'type', 'description');
        $this->config = $this->selectedType()->defaults();
        $this->resetValidation();
    }

    public function render()
    {
        return $this->view()->title(__('common.credentials').' · '.$this->workspace->name);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('common.credentials')" :subheading="__('workspaces.credentials.reusable_credentials_this_workspace_s')">
        @if ($this->canManage)
            <x-slot:actions>
                <flux:button variant="primary" icon="plus" wire:click="create">{{ __('workspaces.credentials.add_credential') }}</flux:button>
            </x-slot:actions>
        @endif
    </x-page-heading>

    @if (session('oauth_status'))
        <flux:callout class="mb-4" variant="success" icon="check-circle" :heading="session('oauth_status')" data-test="oauth-status" />
    @endif
    @if (session('oauth_error'))
        <flux:callout class="mb-4" variant="danger" icon="exclamation-triangle" :heading="__('oauth.could_not_connect')" :text="session('oauth_error')" data-test="oauth-error" />
    @endif

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('common.description') }}</flux:table.column>
            <flux:table.column>{{ __('common.type') }}</flux:table.column>
            <flux:table.column>{{ __('common.settings') }}</flux:table.column>
            <flux:table.column>{{ __('workspaces.credentials.used_by') }}</flux:table.column>
            <flux:table.column sticky class="bg-white dark:bg-zinc-800"></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->credentials as $credential)
                @php($credentialType = $credential->credentialType())
                <flux:table.row :key="$credential->id">
                    <flux:table.cell class="min-w-32 whitespace-normal">
                        <span class="font-medium text-zinc-800 wrap-anywhere dark:text-white">{{ $credential->description }}</span>
                    </flux:table.cell>
                    <flux:table.cell><flux:badge size="sm">{{ $credentialType->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell class="min-w-64 whitespace-normal">
                        <dl class="grid max-w-md grid-cols-[auto_minmax(0,1fr)] items-center gap-x-3 gap-y-1 text-sm">
                            @foreach ($credentialType->fields() as $field)
                                @continue(! $field->required && blank($credential->config[$field->name] ?? null))
                                <dt class="whitespace-nowrap text-zinc-500">{{ $field->label }}</dt>
                                <dd class="min-w-0 wrap-anywhere">
                                    @if ($field->secret)
                                        @if ($this->canManage)
                                            <x-revealable-value :reveal="'reveal(\''.$credential->id.'\', \''.$field->name.'\')'" :can-reveal="true" />
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
                            @if ($credentialType instanceof OAuthCredentialType)
                                <dt class="whitespace-nowrap text-zinc-500">{{ __('oauth.account') }}</dt>
                                <dd class="min-w-0 wrap-anywhere">
                                    @if (OAuthCredentialType::isConnected($credential))
                                        <span data-test="oauth-account">{{ $credential->config['account'] ?? __('oauth.connected_short') }}</span>
                                    @else
                                        <flux:badge size="sm" color="amber">{{ __('oauth.not_connected_short') }}</flux:badge>
                                    @endif
                                </dd>
                            @endif
                        </dl>
                    </flux:table.cell>
                    <flux:table.cell data-test="credential-usage">
                        @php($usedBy = $this->workflowsByCredential[$credential->id] ?? [])
                        @if ($usedBy === [])
                            <flux:badge size="sm" color="zinc">{{ __('workspaces.credentials.unused') }}</flux:badge>
                        @else
                            <flux:tooltip interactive>
                                <flux:badge as="button" size="sm" color="blue" icon="share">{{ trans_choice('workspaces.credentials.workflow_count', count($usedBy), ['count' => count($usedBy)]) }}</flux:badge>
                                <flux:tooltip.content class="max-w-xs">
                                    <ul class="space-y-0.5">
                                        @foreach ($usedBy as $workflow)
                                            <li wire:key="usage-{{ $credential->id }}-{{ $workflow->id }}">
                                                <a href="{{ route('workspaces.workflows.edit', [$workspace, $workflow]) }}" wire:navigate class="wrap-anywhere hover:underline">{{ $workflow->name }}</a>
                                            </li>
                                        @endforeach
                                    </ul>
                                </flux:tooltip.content>
                            </flux:tooltip>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end" sticky class="whitespace-nowrap bg-white dark:bg-zinc-800">
                        @if ($this->canManage)
                            @if ($credentialType instanceof OAuthCredentialType)
                                <flux:tooltip :content="OAuthCredentialType::isConnected($credential) ? __('oauth.reconnect') : __('oauth.connect')">
                                    <flux:button size="sm" variant="ghost" icon="arrow-path" :href="route('workspaces.credentials.connect', [$workspace, $credential])"
                                        :aria-label="OAuthCredentialType::isConnected($credential) ? __('oauth.reconnect') : __('oauth.connect')" data-test="oauth-connect" />
                                </flux:tooltip>
                            @endif
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit(@js($credential->id))" :aria-label="__('common.edit')" />
                            <flux:button size="sm" variant="ghost" icon="trash" x-on:click="$confirm({{ \Illuminate\Support\Js::from(['title' => __('workspaces.credentials.delete_credential'), 'message' => __('workspaces.credentials.delete_this_credential_workflows'), 'confirm' => __('common.delete')]) }}).then((ok) => ok && $wire.delete(@js($credential->id)))" :aria-label="__('common.delete')" />
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('workspaces.credentials.no_credentials_yet') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="credential-form" class="md:w-lg">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('workspaces.credentials.edit_credential') : __('workspaces.credentials.add_credential') }}</flux:heading>

            <flux:select wire:model.live="type" :label="__('common.type')" :disabled="(bool) $editingId">
                @foreach ($this->types as $typeKey => $credentialType)
                    <flux:select.option :value="$typeKey" wire:key="type-{{ $typeKey }}">{{ $credentialType->label() }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:text class="-mt-4 text-sm">{{ $this->selectedType()->description() }}</flux:text>

            @if ($this->connectsNewAccount())
                @php($oauthType = $this->selectedType())
                @if ($oauthType->isConfigured())
                    <flux:text class="text-sm">{{ __('oauth.connect_from_here', ['provider' => $oauthType->label()]) }}</flux:text>
                    <div class="flex justify-end gap-2">
                        <flux:modal.close><flux:button variant="ghost">{{ __('common.cancel') }}</flux:button></flux:modal.close>
                        <flux:button variant="primary" icon="link" wire:click="connect" data-test="connect-account">{{ __('workflows.editor.connect_provider', ['provider' => $oauthType->label()]) }}</flux:button>
                    </div>
                @else
                    <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('workflows.editor.provider_not_set_up', ['provider' => $oauthType->label(), 'keys' => implode(' and ', $oauthType->environmentKeys())])" data-test="oauth-not-set-up" />
                    <div class="flex justify-end">
                        <flux:modal.close><flux:button variant="ghost">{{ __('common.cancel') }}</flux:button></flux:modal.close>
                    </div>
                @endif
            @else
            <flux:input wire:model="description" :label="__('common.description')" :placeholder="__('workspaces.credentials.description_placeholder')" required
                :description="__('workspaces.credentials.description_help')" />

            @foreach ($this->selectedType()->fields() as $field)
                <div wire:key="field-{{ $type }}-{{ $field->name }}">
                    @if ($field->type === 'select')
                        <flux:select wire:model="config.{{ $field->name }}" :label="$field->label">
                            @foreach ($field->options as $optionValue => $optionLabel)
                                <flux:select.option :value="$optionValue">{{ $optionLabel }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    @elseif ($field->secret && $field->multiline)
                        <flux:textarea wire:model="config.{{ $field->name }}" rows="6" class="font-mono text-xs" spellcheck="false" autocomplete="off" :label="$field->label"
                            :description="$editingId ? __('common.leave_empty_to_keep_the') : $field->help" />
                    @elseif ($field->secret)
                        <flux:input wire:model="config.{{ $field->name }}" type="password" viewable autocomplete="off" :label="$field->label"
                            :description="$editingId ? __('common.leave_empty_to_keep_the') : $field->help" />
                    @else
                        <flux:input wire:model="config.{{ $field->name }}" :type="$field->type === 'url' ? 'url' : 'text'" :label="$field->label" :description="$field->help" />
                    @endif
                </div>
            @endforeach


            @if ($this->selectedType() instanceof OAuthCredentialType)
                @php($oauthType = $this->selectedType())
                <flux:text class="text-sm">{{ __('oauth.after_saving_you_go_to', ['provider' => $oauthType->label()]) }}</flux:text>
                @if ($oauthType->fields() !== [])
                    <flux:input :label="__('oauth.callback_url')" :value="$oauthType->callbackUrl()" readonly copyable :description="__('oauth.register_this_callback_url')" />
                @endif
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('common.cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('common.save') }}</flux:button>
            </div>
            @endif
        </form>
    </flux:modal>
</section>
