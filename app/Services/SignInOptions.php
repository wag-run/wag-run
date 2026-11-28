<?php

namespace App\Services;

use App\Models\OidcDomain;
use App\Models\OidcProvider;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Collection;

/**
 * Works out which ways in the sign-in page offers for an email address.
 *
 * It depends only on the email's domain and the organization's providers,
 * never on whether the email has an account, so the sign-in page can't be
 * used to find out who has one.
 */
class SignInOptions
{
    /**
     * The enabled providers that accept this email address, the one whose
     * domains include it first.
     *
     * @return Collection<int, OidcProvider>
     */
    public function providersFor(string $email): Collection
    {
        $domain = OidcDomain::ofEmail($email);

        $providers = Organization::current()?->oidcProviders()
            ->where('enabled', true)
            ->with('domains')
            ->orderBy('name')
            ->get() ?? new Collection;

        return $providers
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
