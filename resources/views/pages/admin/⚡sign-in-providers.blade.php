<?php

use App\Models\OidcDomain;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Support\Audit;
use App\Support\OidcClient;
use Flux\Flux;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

new class extends Component {
    #[Locked]
    public ?string $editingId = null;

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

    #[Locked]
    public ?string $editingDomainId = null;

    public string $domain = '';

    public string $domainProviderId = '';

    #[Computed]
    public function providers()
    {
        return Organization::current()->oidcProviders()->withCount('identities')->orderBy('name')->get();
    }

    #[Computed]
    public function domains()
    {
        return Organization::current()->oidcDomains()->with('provider')->orderBy('domain')->get();
    }

    public function create(): void
    {
        $this->authorize('org-admin');
        $this->reset();
        $this->resetValidation();
        Flux::modal('provider-form')->show();
    }

    public function edit(string $id): void
    {
        $this->authorize('org-admin');

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
        $this->authorize('org-admin');

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
            $this->addError('name', __('admin.sign_in_providers.another_provider_already_uses_this'));

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
        Flux::toast(variant: 'success', text: __('admin.sign_in_providers.sign_in_provider_saved'));
        $this->reset();
    }

    public function test(string $id, OidcClient $client): void
    {
        $this->authorize('org-admin');

        $provider = $this->findProvider($id);

        try {
            $client->discover($provider);
            Flux::toast(variant: 'success', text: __('admin.sign_in_providers.name_answered_with_a_valid', ['name' => $provider->name]));
        } catch (RuntimeException $exception) {
            Flux::toast(variant: 'danger', text: $exception->getMessage());
        }
    }

    public function delete(string $id): void
    {
        $this->authorize('org-admin');

        $provider = $this->findProvider($id);
        $provider->delete();
        Audit::record('oidc_provider.deleted', $provider);

        Flux::toast(text: __('admin.sign_in_providers.sign_in_provider_deleted'));
    }

    public function addDomain(): void
    {
        $this->authorize('org-admin');
        $this->reset('editingDomainId', 'domain');
        $this->domainProviderId = (string) $this->providers->first()?->id;
        $this->resetValidation();

        Flux::modal('domain-form')->show();
    }

    public function editDomain(string $id): void
    {
        $this->authorize('org-admin');

        $domain = $this->findDomain($id);
        $this->editingDomainId = $domain->id;
        $this->domain = $domain->domain;
        $this->domainProviderId = $domain->oidc_provider_id;
        $this->resetValidation();

        Flux::modal('domain-form')->show();
    }

    public function saveDomain(): void
    {
        $this->authorize('org-admin');

        $organization = Organization::current();
        $this->domain = OidcDomain::normalize($this->domain);

        $this->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/',
                Rule::unique('oidc_domains', 'domain')->where('organization_id', $organization->id)->ignore($this->editingDomainId)],
            'domainProviderId' => ['required', Rule::exists('oidc_providers', 'id')->where('organization_id', $organization->id)],
        ], [
            'domain.required' => __('admin.sign_in_providers.enter_a_domain_such_as'),
            'domain.regex' => __('admin.sign_in_providers.enter_a_domain_such_as'),
            'domain.unique' => __('admin.sign_in_providers.this_domain_already_belongs_to'),
            'domainProviderId.required' => __('admin.sign_in_providers.choose_a_provider'),
            'domainProviderId.exists' => __('admin.sign_in_providers.choose_a_provider'),
        ]);

        $domain = $this->editingDomainId ? $this->findDomain($this->editingDomainId) : $organization->oidcDomains()->make();
        $domain->fill(['domain' => $this->domain, 'oidc_provider_id' => $this->domainProviderId]);

        $creating = ! $domain->exists;
        $changed = array_keys($domain->getDirty());
        $domain->save();

        Audit::record($creating ? 'oidc_provider.domain_added' : 'oidc_provider.domain_updated', $domain, metadata: array_filter([
            'provider' => $domain->provider->name,
            'changed' => $creating ? null : $changed,
        ]), label: $domain->domain);

        Flux::modal('domain-form')->close();
        Flux::toast(variant: 'success', text: __('admin.sign_in_providers.email_domain_saved'));
        $this->reset('editingDomainId', 'domain', 'domainProviderId');
    }

    public function deleteDomain(string $id): void
    {
        $this->authorize('org-admin');

        $domain = $this->findDomain($id);
        $domain->delete();
        Audit::record('oidc_provider.domain_deleted', $domain, metadata: ['provider' => $domain->provider->name], label: $domain->domain);

        Flux::toast(text: __('admin.sign_in_providers.email_domain_deleted'));
    }

    public function callbackUrl(?string $slug = null): string
    {
        return route('oidc.callback', $slug ?? $this->editingSlug ?? (Str::slug($this->name) ?: 'provider'));
    }

    protected function findProvider(string $id): OidcProvider
    {
        return Organization::current()->oidcProviders()->findOrFail($id);
    }

    protected function findDomain(string $id): OidcDomain
    {
        return Organization::current()->oidcDomains()->findOrFail($id);
    }

    public function render()
    {
        return $this->view()->title(__('admin.sign_in_providers.title'));
    }
}; ?>

<section class="w-full">
    <x-pages::admin.layout :heading="__('admin.sign_in_providers.title')" :subheading="__('admin.sign_in_providers.how_people_sign_in')">
        <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading>{{ __('common.sign_in_providers') }}</flux:heading>
                <flux:text class="mt-1">{{ __('admin.sign_in_providers.let_people_sign_in_with') }}</flux:text>
            </div>
            <flux:button variant="primary" icon="plus" wire:click="create" data-test="add-provider">{{ __('admin.sign_in_providers.add_provider') }}</flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('common.provider') }}</flux:table.column>
                <flux:table.column>{{ __('admin.sign_in_providers.issuer') }}</flux:table.column>
                <flux:table.column>{{ __('admin.sign_in_providers.new_people') }}</flux:table.column>
                <flux:table.column>{{ __('admin.sign_in_providers.linked_users') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->providers as $provider)
                    <flux:table.row :key="$provider->id">
                        <flux:table.cell>
                            <flux:tooltip :content="__('admin.sign_in_providers.redirect_uri_url', ['url' => $this->callbackUrl($provider->slug)])">
                                <span class="font-medium underline decoration-dotted decoration-zinc-400 underline-offset-4 dark:decoration-zinc-500">{{ $provider->name }}</span>
                            </flux:tooltip>
                            @unless ($provider->enabled)
                                <flux:badge size="sm" class="ms-1">{{ __('admin.sign_in_providers.off') }}</flux:badge>
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-xs">{{ $provider->issuer }}</flux:table.cell>
                        <flux:table.cell>{{ $provider->allow_registration ? __('admin.sign_in_providers.get_an_account') : __('admin.sign_in_providers.need_an_invitation') }}</flux:table.cell>
                        <flux:table.cell>{{ $provider->identities_count }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="signal" wire:click="test('{{ $provider->id }}')" :tooltip="__('admin.sign_in_providers.check_discovery')" :aria-label="__('admin.sign_in_providers.check_discovery')" />
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit('{{ $provider->id }}')" :aria-label="__('common.edit')" />
                            <flux:button size="sm" variant="ghost" icon="trash" x-on:click="$confirm({{ \Illuminate\Support\Js::from(['title' => __('admin.sign_in_providers.delete_sign_in_provider'), 'message' => __('admin.sign_in_providers.delete_name_people_who_signed', ['name' => $provider->name]), 'confirm' => __('common.delete')]) }}).then((ok) => ok && $wire.delete('{{ $provider->id }}'))"
                                :aria-label="__('common.delete')" />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('admin.sign_in_providers.no_sign_in_providers_yet') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <div class="mt-10 mb-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading>{{ __('admin.sign_in_providers.email_domains') }}</flux:heading>
                <flux:text class="mt-1">{{ __('admin.sign_in_providers.a_provider_with_email_domains') }}</flux:text>
            </div>
            @if ($this->providers->isNotEmpty())
                <flux:button icon="plus" wire:click="addDomain" data-test="add-domain">{{ __('admin.sign_in_providers.add_domain') }}</flux:button>
            @endif
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('admin.sign_in_providers.domain') }}</flux:table.column>
                <flux:table.column>{{ __('common.provider') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->domains as $domain)
                    <flux:table.row :key="$domain->id">
                        <flux:table.cell class="font-medium">{{ $domain->domain }}</flux:table.cell>
                        <flux:table.cell>{{ $domain->provider->name }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editDomain('{{ $domain->id }}')" :aria-label="__('common.edit')" />
                            <flux:button size="sm" variant="ghost" icon="trash" x-on:click="$confirm({{ \Illuminate\Support\Js::from(['title' => __('admin.sign_in_providers.delete_email_domain'), 'message' => __('admin.sign_in_providers.delete_domain_if_it_was', ['domain' => $domain->domain, 'name' => $domain->provider->name]), 'confirm' => __('common.delete')]) }}).then((ok) => ok && $wire.deleteDomain('{{ $domain->id }}'))"
                                :aria-label="__('common.delete')" />
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="3" class="text-center text-zinc-500">
                            {{ $this->providers->isEmpty() ? __('admin.sign_in_providers.add_a_provider_first_then') : __('admin.sign_in_providers.no_email_domains_yet') }}
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <flux:modal name="domain-form" class="md:w-md">
            <form wire:submit="saveDomain" class="space-y-6">
                <flux:heading size="lg">{{ $editingDomainId ? __('admin.sign_in_providers.edit_email_domain') : __('admin.sign_in_providers.add_email_domain') }}</flux:heading>

                <flux:input wire:model="domain" :label="__('admin.sign_in_providers.domain')" placeholder="acme.com" required />

                <flux:select wire:model="domainProviderId" :label="__('common.provider')" :description="__('admin.sign_in_providers.each_domain_belongs_to_one')">
                    @foreach ($this->providers as $provider)
                        <flux:select.option :value="$provider->id">{{ $provider->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('common.cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-domain">{{ $editingDomainId ? __('common.save') : __('admin.sign_in_providers.add_domain') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        <flux:modal name="provider-form" class="md:w-xl">
            <form wire:submit="save" class="space-y-6">
                <flux:heading size="lg">{{ $editingId ? __('admin.sign_in_providers.edit_sign_in_provider') : __('admin.sign_in_providers.add_sign_in_provider') }}</flux:heading>

                <flux:input wire:model.live.debounce.300ms="name" :label="__('common.name')" :description="__('admin.sign_in_providers.shown_on_the_sign_in')" required />
                <flux:input wire:model="issuer" :label="__('admin.sign_in_providers.issuer_url')" placeholder="https://id.example.com/realms/main" :description="__('admin.sign_in_providers.base_url_that_serves_well')" required />

                <flux:field>
                    <flux:label>{{ __('admin.sign_in_providers.redirect_uri') }}</flux:label>
                    <flux:description>{{ __('admin.sign_in_providers.register_this_with_the_provider') }}</flux:description>
                    <flux:input :value="$this->callbackUrl()" readonly copyable />
                </flux:field>

                <flux:input wire:model="clientId" :label="__('admin.sign_in_providers.client_id')" required />
                <flux:input wire:model="clientSecret" type="password" :label="__('admin.sign_in_providers.client_secret')" viewable
                    :placeholder="$editingId ? __('admin.sign_in_providers.leave_blank_to_keep_the') : ''" :required="! $editingId" />
                <flux:input wire:model="scopes" :label="__('admin.sign_in_providers.scopes')" />

                <flux:switch wire:model="enabled" :label="__('admin.sign_in_providers.show_on_the_sign_in')" />
                <flux:switch wire:model="allowRegistration" :label="__('admin.sign_in_providers.create_accounts_for_new_people')"
                    :description="__('admin.sign_in_providers.new_people_join_as_organization')" />
                <flux:switch wire:model="trustEmail" :label="__('admin.sign_in_providers.trust_email_addresses_from_this')"
                    :description="__('admin.sign_in_providers.link_sign_ins_to_existing')" />

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('common.cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-provider">{{ __('common.save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    </x-pages::admin.layout>
</section>
