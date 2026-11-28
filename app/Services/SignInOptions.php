<?php

namespace App\Services;

use App\Models\OidcDomain;
use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Works out which ways in the sign-in page offers for an email address, from
 * the organization the email belongs to:
 *
 * 1. The email has an account: the organization it last worked in, or the
 *    first it's active in.
 * 2. Otherwise a provider claims the email's domain: that provider's organization.
 * 3. Otherwise none, and the page offers only the password, the default.
 *
 * An account wins over a domain claimed by another organization. Someone
 * deactivated everywhere gets the default, like an unknown email.
 *
 * Emails on a claimed domain always get the same page, account or not. An
 * account off any claimed domain, at an organization whose options differ
 * from the default (such as a provider with no domains), sees different
 * options from an unknown email, which shows that the account exists. That's
 * an accepted trade-off of showing an organization's own options before
 * sign-in: hiding it would mean offering such people only the password.
 */
class SignInOptions
{
    /**
     * The organizations an email's account is active in, by name. Someone in
     * more than one picks which to sign in to.
     *
     * @return Collection<int, Organization>
     */
    public function organizationsOf(string $email): Collection
    {
        $user = User::query()->where('email', Str::lower(trim($email)))->first();

        return $user ? $user->organizations()->wherePivotNull('deactivated_at')->orderBy('name')->get() : new Collection;
    }

    public function organizationFor(string $email): ?Organization
    {
        $user = User::query()->where('email', Str::lower(trim($email)))->first();

        if ($user) {
            $active = $user->organizations()->wherePivotNull('deactivated_at');

            $organization = ($user->current_organization_id ? (clone $active)->find($user->current_organization_id) : null)
                ?? $active->oldest('organization_user.created_at')->first();

            if ($organization) {
                return $organization;
            }
        }

        return $this->domainOwner($email)?->organization;
    }

    /**
     * The enabled provider that has verified the email's domain, in whichever
     * organization. That organization speaks for people at the domain.
     */
    public function domainOwner(string $email): ?OidcProvider
    {
        $domain = OidcDomain::ofEmail($email);

        return $domain === null ? null : OidcDomain::query()
            ->verified()
            ->where('domain', $domain)
            ->whereHas('provider', fn ($query) => $query->where('enabled', true))
            ->oldest()
            ->first()?->provider;
    }

    /**
     * Everything the sign-in page needs for an email: its organization, the
     * providers it offers, the one claiming the email's domain, and whether a
     * password is accepted. An email with no organization gets the default:
     * a password and no providers.
     *
     * An email whose account is in several organizations and hasn't picked
     * one gets them as `organizations`, and nothing else, so the page can ask.
     *
     * @return array{organizations: Collection<int, Organization>, organization: ?Organization, providers: Collection<int, OidcProvider>, claimingProvider: ?OidcProvider, passwordAllowed: bool}
     */
    public function forEmail(string $email, ?string $chosenOrganizationId = null): array
    {
        $organizations = $this->organizationsOf($email);

        if ($organizations->count() > 1 && ! $organizations->contains('id', $chosenOrganizationId)) {
            return [
                'organizations' => $organizations,
                'organization' => null,
                'providers' => new Collection,
                'claimingProvider' => null,
                'passwordAllowed' => true,
            ];
        }

        $organization = $organizations->firstWhere('id', $chosenOrganizationId) ?? $this->organizationFor($email);
        $providers = $this->providersFor($email, $organization);
        $domain = OidcDomain::ofEmail($email);

        return [
            'organizations' => new Collection,
            'organization' => $organization,
            'providers' => $providers,
            'claimingProvider' => $providers->first(fn (OidcProvider $provider) => $this->claims($provider, $domain)),
            'passwordAllowed' => $organization->password_sign_in ?? true,
        ];
    }

    /**
     * The enabled providers of the email's organization that accept it, the one
     * whose domains include it first. None for an email with no organization.
     *
     * @return Collection<int, OidcProvider>
     */
    public function providersFor(string $email, ?Organization $organization = null): Collection
    {
        $organization ??= $this->organizationFor($email);

        if (! $organization) {
            return new Collection;
        }

        $domain = OidcDomain::ofEmail($email);

        return $organization->oidcProviders()
            ->where('enabled', true)
            ->with('domains')
            ->orderBy('name')
            ->get()
            ->filter(fn (OidcProvider $provider) => $provider->domains->isEmpty() || $this->claims($provider, $domain))
            ->sortBy(fn (OidcProvider $provider) => $this->claims($provider, $domain) ? 0 : 1)
            ->values();
    }

    /**
     * The provider whose domains include this email address, if any.
     */
    public function providerClaiming(string $email): ?OidcProvider
    {
        $domain = OidcDomain::ofEmail($email);

        return $this->providersFor($email)->first(fn (OidcProvider $provider) => $this->claims($provider, $domain));
    }

    protected function claims(OidcProvider $provider, ?string $domain): bool
    {
        return $domain !== null && $provider->domains->contains('domain', $domain);
    }
}
