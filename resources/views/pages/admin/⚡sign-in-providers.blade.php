<?php

use App\Support\DnsTxtLookup;
use Illuminate\Validation\Rule;
use App\Enums\TwoFactorPolicy;
use App\Models\OidcDomain;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Support\Audit;
use App\Support\OidcClient;
use Flux\Flux;
use Illuminate\Support\Str;
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

    /**
     * The provider whose email domains the domains dialog shows.
     */
    #[Locked]
    public ?string $domainsProviderId = null;

    public bool $addingDomain = false;

    /**
     * The domain whose verification steps the domains dialog shows (hosted only).
     */
    #[Locked]
    public ?string $verifyingDomainId = null;

    public string $newDomain = '';

    public bool $passwordSignIn = true;

    public string $twoFactor = 'optional';

    public function mount(): void
    {
        $this->passwordSignIn = Organization::current()->password_sign_in;
        $this->twoFactor = Organization::current()->twoFactorPolicy()->value;
    }

    /**
     * How many people in the organization have two-factor authentication set up.
     */
    #[Computed]
    public function peopleWithTwoFactor(): int
    {
        return Organization::current()->users()->whereNotNull('two_factor_confirmed_at')->count();
    }

    /**
     * Save the sign-in methods form. Password sign-in can only be turned off
     * while an enabled provider gives people another way in.
     */
    public function saveSignInMethods(): void
    {
        $this->authorize('org-admin');

        $organization = Organization::current();

        if (! $this->passwordSignIn && ! $organization->oidcProviders()->where('enabled', true)->exists()) {
            $this->addError('passwordSignIn', __('admin.sign_in_providers.add_and_enable_a_sign'));

            return;
        }

        $this->validate(['twoFactor' => ['required', Rule::enum(TwoFactorPolicy::class)]]);

        $organization->password_sign_in = $this->passwordSignIn;
        $organization->two_factor = $this->twoFactor;

        if ($organization->isDirty()) {
            $changed = $organization->changedFields();
            $organization->save();
            Audit::record('organization.settings_updated', $organization, metadata: ['changed' => $changed]);
        }

        Flux::toast(variant: 'success', text: __('admin.sign_in_providers.sign_in_methods_saved'));
    }

    /**
     * Whether turning this provider off, or deleting it, would leave no way in:
     * password sign-in is off and it's the only enabled provider.
     */
    protected function isLastWayIn(OidcProvider $provider): bool
    {
        return ! Organization::current()->password_sign_in
            && $provider->enabled
            && ! Organization::current()->oidcProviders()->where('enabled', true)->whereKeyNot($provider->id)->exists();
    }

    #[Computed]
    public function providers()
    {
        return Organization::current()->oidcProviders()
            ->withCount('identities')
            ->with(['domains' => fn ($query) => $query->orderBy('domain')])
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function domainsProvider(): ?OidcProvider
    {
        return $this->domainsProviderId
            ? Organization::current()->oidcProviders()->with(['domains' => fn ($query) => $query->orderBy('domain')])->find($this->domainsProviderId)
            : null;
    }

    public function create(): void
    {
        $this->authorize('org-admin');
        $this->resetExcept('passwordSignIn', 'twoFactor');
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

        if (! $this->editingId && OidcProvider::query()->where('slug', $slug)->exists()) {
            $slug .= '-'.Str::lower(Str::random(4));
        }

        if ($this->allowRegistration && (! $this->editingId || ! $this->findProvider($this->editingId)->domains()->exists())) {
            $this->addError('allowRegistration', __('admin.sign_in_providers.add_an_email_domain_first'));

            return;
        }

        if (! $this->enabled && $this->editingId && $this->isLastWayIn($this->findProvider($this->editingId))) {
            $this->addError('enabled', __('admin.sign_in_providers.this_is_the_only_way'));

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
        $this->resetExcept('passwordSignIn', 'twoFactor');
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

        if ($this->isLastWayIn($provider)) {
            Flux::toast(variant: 'danger', text: __('admin.sign_in_providers.this_is_the_only_way'));

            return;
        }

        $provider->delete();
        Audit::record('oidc_provider.deleted', $provider);

        Flux::toast(text: __('admin.sign_in_providers.sign_in_provider_deleted'));
    }

    public function manageDomains(string $id): void
    {
        $this->authorize('org-admin');

        $this->domainsProviderId = $this->findProvider($id)->id;
        $this->reset('addingDomain', 'newDomain', 'verifyingDomainId');
        $this->resetValidation();

        Flux::modal('provider-domains')->show();
    }

    public function addDomain(): void
    {
        $this->authorize('org-admin');

        $organization = Organization::current();
        $provider = $this->findProvider((string) $this->domainsProviderId);
        $this->newDomain = OidcDomain::normalize($this->newDomain);

        $this->validate([
            'newDomain' => ['required', 'string', 'max:253', 'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/'],
        ], [
            'newDomain.required' => __('admin.sign_in_providers.enter_a_domain_such_as'),
            'newDomain.regex' => __('admin.sign_in_providers.enter_a_domain_such_as'),
        ]);

        $existing = $organization->oidcDomains()->with('provider')->where('domain', $this->newDomain)->first();

        if (! $existing && (new OidcDomain(['organization_id' => $organization->id, 'domain' => $this->newDomain]))->isVerifiedElsewhere()) {
            $this->addError('newDomain', __('admin.sign_in_providers.another_organization_has_verified', ['domain' => $this->newDomain]));

            return;
        }

        if ($existing) {
            $this->addError('newDomain', __('admin.sign_in_providers.domain_already_belongs_to_name', ['domain' => $existing->domain, 'name' => $existing->provider->name]));

            return;
        }

        $domain = $provider->domains()->create(['organization_id' => $organization->id, 'domain' => $this->newDomain]);
        Audit::record('oidc_provider.domain_added', $domain, metadata: ['provider' => $provider->name], label: $domain->domain);

        $this->reset('addingDomain', 'newDomain');
    }

    /**
     * Show or hide a pending domain's verification steps.
     */
    public function toggleVerification(string $id): void
    {
        $this->authorize('org-admin');

        $domain = Organization::current()->oidcDomains()->findOrFail($id);
        $this->verifyingDomainId = $this->verifyingDomainId === $domain->id ? null : $domain->id;
        $this->resetValidation('verify');
    }

    /**
     * Look up the domain's TXT record and mark it verified when it holds the
     * expected value.
     */
    public function checkDomain(string $id, DnsTxtLookup $dns): void
    {
        $this->authorize('org-admin');

        $domain = Organization::current()->oidcDomains()->with('provider')->findOrFail($id);
        $this->resetValidation('verify');

        if ($domain->isVerified()) {
            return;
        }

        if ($domain->isVerifiedElsewhere()) {
            $this->addError('verify', __('admin.sign_in_providers.another_organization_has_verified', ['domain' => $domain->domain]));

            return;
        }

        if (! in_array($domain->txtRecordValue(), $dns->records($domain->txtRecordName()), true)) {
            $this->addError('verify', __('admin.sign_in_providers.not_found_yet_dns_changes'));

            return;
        }

        $domain->update(['verified_at' => now()]);
        Audit::record('oidc_provider.domain_verified', $domain, metadata: ['provider' => $domain->provider->name], label: $domain->domain);

        $this->verifyingDomainId = null;
        Flux::toast(variant: 'success', text: __('admin.sign_in_providers.domain_verified', ['domain' => $domain->domain]));
    }

    public function deleteDomain(string $id): void
    {
        $this->authorize('org-admin');

        $domain = Organization::current()->oidcDomains()->with('provider')->findOrFail($id);
        $domain->delete();
        Audit::record('oidc_provider.domain_deleted', $domain, metadata: ['provider' => $domain->provider->name], label: $domain->domain);

        $provider = $domain->provider;

        if ($provider->allow_registration && ! $provider->domains()->exists()) {
            $provider->update(['allow_registration' => false]);
            Audit::record('oidc_provider.updated', $provider, metadata: ['changed' => ['allow_registration']]);
            Flux::toast(text: __('admin.sign_in_providers.name_no_longer_creates_accounts', ['name' => $provider->name]));
        }
    }

    public function callbackUrl(?string $slug = null): string
    {
        return route('oidc.callback', $slug ?? $this->editingSlug ?? (Str::slug($this->name) ?: 'provider'));
    }

    protected function findProvider(string $id): OidcProvider
    {
        return Organization::current()->oidcProviders()->findOrFail($id);
    }

    public function render()
    {
        return $this->view()->title(__('admin.sign_in_providers.title'));
    }
}; ?>

<section class="w-full">
    <x-pages::admin.layout :heading="__('admin.sign_in_providers.title')" :subheading="__('admin.sign_in_providers.how_people_sign_in')">
        <form wire:submit="saveSignInMethods" class="mb-10 max-w-2xl space-y-6">
            <flux:heading>{{ __('admin.sign_in_providers.sign_in_methods') }}</flux:heading>
            <flux:switch wire:model="passwordSignIn" :label="__('admin.sign_in_providers.password_sign_in')"
                :description="__('admin.sign_in_providers.people_sign_in_with_their')" data-test="password-sign-in" />

            <flux:field x-show="$wire.passwordSignIn" x-cloak>
                <flux:label>{{ __('common.two_factor_authentication') }}</flux:label>
                <flux:description>{{ __('admin.sign_in_providers.for_password_sign_ins_ignored') }}</flux:description>
                <flux:radio.group wire:model.live="twoFactor" variant="segmented" data-test="two-factor-policy">
                    @foreach (TwoFactorPolicy::cases() as $policy)
                        <flux:radio :value="$policy->value">{{ $policy->label() }}</flux:radio>
                    @endforeach
                </flux:radio.group>
                @if ($twoFactor === TwoFactorPolicy::Required->value)
                    <flux:text class="mt-2">{{ __('admin.sign_in_providers.people_without_it_set_it') }}</flux:text>
                @elseif ($twoFactor === TwoFactorPolicy::Disabled->value && $this->peopleWithTwoFactor > 0)
                    <flux:text class="mt-2">{{ trans_choice('admin.sign_in_providers.count_people_have_it_set', $this->peopleWithTwoFactor, ['count' => $this->peopleWithTwoFactor]) }}</flux:text>
                @endif
            </flux:field>
            <flux:button type="submit" variant="primary" data-test="save-sign-in-methods">{{ __('common.save_settings') }}</flux:button>
        </form>

        <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <flux:heading>{{ __('common.sign_in_providers') }}</flux:heading>
                <flux:text class="mt-1">{{ __('admin.sign_in_providers.let_people_sign_in_with') }}</flux:text>
            </div>
            <flux:button icon="plus" wire:click="create" data-test="add-provider">{{ __('admin.sign_in_providers.add_provider') }}</flux:button>
        </div>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('common.provider') }}</flux:table.column>
                <flux:table.column>{{ __('admin.sign_in_providers.issuer') }}</flux:table.column>
                <flux:table.column>{{ __('admin.sign_in_providers.domains') }}</flux:table.column>
                <flux:table.column>{{ __('admin.sign_in_providers.new_people') }}</flux:table.column>
                <flux:table.column>{{ __('admin.sign_in_providers.linked_users') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->providers as $provider)
                    <flux:table.row :key="$provider->id">
                        <flux:table.cell>
                            <span class="font-medium">{{ $provider->name }}</span>
                            @unless ($provider->enabled)
                                <flux:badge size="sm" class="ms-1">{{ __('admin.sign_in_providers.off') }}</flux:badge>
                            @endunless
                        </flux:table.cell>
                        <flux:table.cell class="font-mono text-xs">{{ $provider->issuer }}</flux:table.cell>
                        <flux:table.cell>
                            <button type="button" wire:click="manageDomains('{{ $provider->id }}')" data-test="domains-{{ $provider->slug }}"
                                class="cursor-pointer underline decoration-dotted decoration-zinc-400 underline-offset-4 dark:decoration-zinc-500 {{ $provider->domains->isEmpty() ? 'text-zinc-500 dark:text-zinc-400' : '' }}">
                                @if ($provider->domains->isEmpty())
                                    {{ __('admin.sign_in_providers.any') }}
                                @else
                                    {{ $provider->domains->first()->domain }}
                                    @if ($provider->domains->count() > 1)
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('admin.sign_in_providers.plus_count', ['count' => $provider->domains->count() - 1]) }}</span>
                                    @endif
                                @endif
                            </button>
                            @if ($provider->domains->contains(fn ($domain) => ! $domain->isVerified()))
                                <span class="ms-1 inline-block size-2 rounded-full bg-amber-500 align-middle" title="{{ __('admin.sign_in_providers.a_domain_is_waiting_on') }}" aria-label="{{ __('admin.sign_in_providers.a_domain_is_waiting_on') }}" data-test="pending-domains"></span>
                            @endif
                        </flux:table.cell>
                        <flux:table.cell>{{ $provider->allow_registration && $provider->domains->contains(fn ($domain) => $domain->isVerified()) ? __('admin.sign_in_providers.get_an_account') : __('admin.sign_in_providers.need_an_invitation') }}</flux:table.cell>
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
                        <flux:table.cell colspan="6" class="text-center text-zinc-500">{{ __('admin.sign_in_providers.no_sign_in_providers_yet') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        <flux:modal name="provider-domains" class="md:w-lg">
            @if ($this->domainsProvider)
                <div class="space-y-6">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <flux:heading size="lg">{{ __('admin.sign_in_providers.name_email_domains', ['name' => $this->domainsProvider->name]) }}</flux:heading>
                            <flux:text class="mt-1">{{ __('admin.sign_in_providers.name_accepts_only_email_addresses', ['name' => $this->domainsProvider->name]) }}</flux:text>
                        </div>
                        @unless ($addingDomain)
                            <flux:button size="sm" icon="plus" wire:click="$set('addingDomain', true)" data-test="add-domain">{{ __('admin.sign_in_providers.add_domain') }}</flux:button>
                        @endunless
                    </div>

                    @if ($addingDomain)
                        <form wire:submit="addDomain" class="flex items-start gap-2" x-init="$nextTick(() => $el.querySelector('input')?.focus())">
                            <div class="flex-1">
                                <flux:input wire:model="newDomain" placeholder="acme.com" :aria-label="__('admin.sign_in_providers.domain')" />
                                <flux:error name="newDomain" />
                            </div>
                            <flux:button wire:click="$set('addingDomain', false)" variant="ghost">{{ __('common.cancel') }}</flux:button>
                            <flux:button type="submit" variant="primary" data-test="save-domain">{{ __('admin.sign_in_providers.add_domain') }}</flux:button>
                        </form>
                    @endif

                    @unless (config('app.self_hosted'))
                        <flux:text>{{ __('admin.sign_in_providers.pending_domains_already_turn_away') }}</flux:text>
                    @endunless

                    <flux:table>
                        <flux:table.columns>
                            <flux:table.column>{{ __('admin.sign_in_providers.domain') }}</flux:table.column>
                            @unless (config('app.self_hosted'))
                                <flux:table.column>{{ __('common.status') }}</flux:table.column>
                            @endunless
                            <flux:table.column></flux:table.column>
                        </flux:table.columns>
                        <flux:table.rows>
                            @forelse ($this->domainsProvider->domains as $domain)
                                <flux:table.row :key="$domain->id">
                                    <flux:table.cell>{{ $domain->domain }}</flux:table.cell>
                                    @unless (config('app.self_hosted'))
                                        <flux:table.cell>
                                            @if ($domain->isVerified())
                                                <flux:badge size="sm" color="green">{{ __('admin.sign_in_providers.verified') }}</flux:badge>
                                            @else
                                                <flux:badge size="sm" color="amber">{{ __('admin.sign_in_providers.pending_verification') }}</flux:badge>
                                            @endif
                                        </flux:table.cell>
                                    @endunless
                                    <flux:table.cell align="end">
                                        @unless ($domain->isVerified())
                                            <flux:button size="sm" wire:click="toggleVerification('{{ $domain->id }}')" data-test="verify-{{ $domain->domain }}">
                                                {{ $verifyingDomainId === $domain->id ? __('admin.sign_in_providers.hide') : __('admin.sign_in_providers.verify') }}
                                            </flux:button>
                                        @endunless
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteDomain('{{ $domain->id }}')" :aria-label="__('admin.sign_in_providers.remove_domain', ['domain' => $domain->domain])" />
                                    </flux:table.cell>
                                </flux:table.row>
                            @empty
                                <flux:table.row>
                                    <flux:table.cell colspan="3" class="text-center text-zinc-500">{{ __('admin.sign_in_providers.no_domains_name_accepts_any', ['name' => $this->domainsProvider->name]) }}</flux:table.cell>
                                </flux:table.row>
                            @endforelse
                        </flux:table.rows>
                    </flux:table>

                    @if ($verifying = $this->domainsProvider->domains->firstWhere('id', $verifyingDomainId))
                        <div class="space-y-3 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                            <flux:heading>{{ __('admin.sign_in_providers.verify_domain', ['domain' => $verifying->domain]) }}</flux:heading>
                            <flux:text>{{ __('admin.sign_in_providers.add_this_txt_record_at', ['domain' => $verifying->domain]) }}</flux:text>
                            <flux:input :label="__('common.name')" :value="$verifying->txtRecordName()" readonly copyable />
                            <flux:input :label="__('common.value')" :value="$verifying->txtRecordValue()" readonly copyable />
                            <div class="flex flex-wrap items-center gap-3">
                                <flux:button size="sm" variant="primary" wire:click="checkDomain('{{ $verifying->id }}')" data-test="check-domain">{{ __('admin.sign_in_providers.check_now') }}</flux:button>
                                <flux:error name="verify" />
                            </div>
                        </div>
                    @endif

                    <div class="flex justify-end">
                        <flux:modal.close><flux:button variant="ghost">{{ __('common.done') }}</flux:button></flux:modal.close>
                    </div>
                </div>
            @endif
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
                    :description="__('admin.sign_in_providers.also_called_just_in_time_jit')" />
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
