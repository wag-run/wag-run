<?php

use App\Models\OidcProvider;
use App\Models\Organization;
use App\Support\Audit;
use App\Support\OidcClient;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Sign-in providers')] class extends Component {
    #[Locked]
    public ?int $editingId = null;

    /**
     * The slug is fixed when a provider is created, so renaming it does not
     * change the redirect URI registered with the provider.
     */
    #[Locked]
    public ?string $editingSlug = null;

    public string $name = '';

    public string $issuer = '';

    public string $clientId = '';

    public string $clientSecret = '';

    public string $scopes = 'openid email profile';

    public bool $enabled = true;

    public bool $allowRegistration = false;

    public bool $trustEmail = false;

    #[Computed]
    public function providers()
    {
        return Organization::current()->oidcProviders()->withCount('identities')->orderBy('name')->get();
    }

    public function create(): void
    {
        $this->authorize('install-admin');
        $this->reset();
        $this->resetValidation();
        Flux::modal('provider-form')->show();
    }

    public function edit(int $id): void
    {
        $this->authorize('install-admin');

        $provider = $this->findProvider($id);
        $this->editingId = $provider->id;
        $this->editingSlug = $provider->slug;
        $this->name = $provider->name;
        $this->issuer = $provider->issuer;
        $this->clientId = $provider->client_id;
        $this->clientSecret = '';
        $this->scopes = $provider->scopes;
        $this->enabled = $provider->enabled;
        $this->allowRegistration = $provider->allow_registration;
        $this->trustEmail = $provider->trust_email;
        $this->resetValidation();

        Flux::modal('provider-form')->show();
    }

    public function save(): void
    {
        $this->authorize('install-admin');

        $organization = Organization::current();

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'issuer' => ['required', 'url:http,https', 'max:255', function (string $attribute, mixed $value, Closure $fail) {
                try {
                    OidcClient::assertSecureIssuer(rtrim((string) $value, '/'));
                } catch (RuntimeException $exception) {
                    $fail($exception->getMessage());
                }
            }],
            'clientId' => ['required', 'string', 'max:255'],
            'clientSecret' => [$this->editingId ? 'nullable' : 'required', 'string', 'max:2000'],
            'scopes' => ['required', 'string', 'max:255'],
        ]);

        $slug = $this->editingSlug ?? (Str::slug($this->name) ?: 'provider');

        if (! $this->editingId && $organization->oidcProviders()->where('slug', $slug)->exists()) {
            $this->addError('name', __('Another provider already uses this name.'));

            return;
        }

        $provider = $this->editingId ? $this->findProvider($this->editingId) : $organization->oidcProviders()->make();

        $provider->fill([
            'name' => $this->name,
            'slug' => $slug,
            'issuer' => rtrim($this->issuer, '/'),
            'client_id' => $this->clientId,
            'scopes' => $this->scopes,
            'enabled' => $this->enabled,
            'allow_registration' => $this->allowRegistration,
            'trust_email' => $this->trustEmail,
        ]);

        if ($this->clientSecret !== '') {
            $provider->client_secret = $this->clientSecret;
        }

        $creating = ! $provider->exists;
        $changed = array_values(array_diff(array_keys($provider->getDirty()), ['client_secret']));
        $secretChanged = $provider->isDirty('client_secret');
        $provider->save();

        Audit::record($creating ? 'oidc_provider.created' : 'oidc_provider.updated', $provider, metadata: $creating ? [] : array_filter(['changed' => $changed, 'client_secret' => $secretChanged ? 'replaced' : null]));

        Flux::modal('provider-form')->close();
        Flux::toast(variant: 'success', text: __('Sign-in provider saved.'));
        $this->reset();
    }

    public function test(int $id, OidcClient $client): void
    {
        $this->authorize('install-admin');

        $provider = $this->findProvider($id);

        try {
            $client->discover($provider);
            Flux::toast(variant: 'success', text: __(':name answered with a valid discovery document.', ['name' => $provider->name]));
        } catch (RuntimeException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }
    }

    public function delete(int $id): void
    {
        $this->authorize('install-admin');

        $provider = $this->findProvider($id);
        $provider->delete();
        Audit::record('oidc_provider.deleted', $provider);

        Flux::toast(text: __('Sign-in provider deleted.'));
    }

    public function callbackUrl(?string $slug = null): string
    {
        return route('oidc.callback', $slug ?? $this->editingSlug ?? (Str::slug($this->name) ?: 'provider'));
    }

    protected function findProvider(int $id): OidcProvider
    {
        return Organization::current()->oidcProviders()->findOrFail($id);
    }
}; ?>

<section class="w-full">
    <x-page-heading :heading="__('Sign-in providers')" :subheading="__('Let people sign in with an OpenID Connect provider such as Okta, Entra ID, Google Workspace, Keycloak or Authentik. Password sign-in stays available.')">
        <x-slot:actions>
            <flux:button variant="primary" icon="plus" wire:click="create" data-test="add-provider">{{ __('Add provider') }}</flux:button>
        </x-slot:actions>
    </x-page-heading>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Provider') }}</flux:table.column>
            <flux:table.column>{{ __('Issuer') }}</flux:table.column>
            <flux:table.column>{{ __('New people') }}</flux:table.column>
            <flux:table.column>{{ __('Linked users') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->providers as $provider)
                <flux:table.row :key="$provider->id">
                    <flux:table.cell>
                        <span class="font-medium">{{ $provider->name }}</span>
                        @unless ($provider->enabled)
                            <flux:badge size="sm" class="ms-1">{{ __('Off') }}</flux:badge>
                        @endunless
                        <div class="font-mono text-xs text-zinc-500">{{ $this->callbackUrl($provider->slug) }}</div>
                    </flux:table.cell>
                    <flux:table.cell class="font-mono text-xs">{{ $provider->issuer }}</flux:table.cell>
                    <flux:table.cell>{{ $provider->allow_registration ? __('Get an account') : __('Need an invitation') }}</flux:table.cell>
                    <flux:table.cell>{{ $provider->identities_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="signal" wire:click="test({{ $provider->id }})" :tooltip="__('Check discovery')" :aria-label="__('Check discovery')" />
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $provider->id }})" :aria-label="__('Edit')" />
                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $provider->id }})"
                            wire:confirm="{{ __('Delete :name? People who signed in with it keep their accounts and can still use their password.', ['name' => $provider->name]) }}"
                            :aria-label="__('Delete')" />
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('No sign-in providers yet.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="provider-form" class="md:w-xl">
        <form wire:submit="save" class="space-y-6">
            <flux:heading size="lg">{{ $editingId ? __('Edit sign-in provider') : __('Add sign-in provider') }}</flux:heading>

            <flux:input wire:model.live.debounce.300ms="name" :label="__('Name')" :description="__('Shown on the sign-in button: \'Sign in with …\'.')" required />
            <flux:input wire:model="issuer" :label="__('Issuer URL')" placeholder="https://id.example.com/realms/main" :description="__('The base URL that serves /.well-known/openid-configuration.')" required />

            <flux:field>
                <flux:label>{{ __('Redirect URI') }}</flux:label>
                <flux:description>{{ __('Register this with the provider.') }}</flux:description>
                <flux:input :value="$this->callbackUrl()" readonly copyable />
            </flux:field>

            <flux:input wire:model="clientId" :label="__('Client ID')" required />
            <flux:input wire:model="clientSecret" type="password" :label="__('Client secret')" viewable
                :placeholder="$editingId ? __('Leave blank to keep the current secret') : ''" :required="! $editingId" />
            <flux:input wire:model="scopes" :label="__('Scopes')" />

            <flux:switch wire:model="enabled" :label="__('Show on the sign-in page')" />
            <flux:switch wire:model="allowRegistration" :label="__('Create accounts for new people')"
                :description="__('New people join as organization Members with no workspace access. When off, only people who already have an account can sign in this way.')" />
            <flux:switch wire:model="trustEmail" :label="__('Trust email addresses from this provider')"
                :description="__('Link sign-ins to existing accounts by email even when the provider does not mark the email verified. Turn on only for a provider your organization controls.')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-provider">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</section>
