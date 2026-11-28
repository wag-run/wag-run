<?php

namespace App\Services;

use App\Enums\OrgRole;
use App\Models\OidcIdentity;
use App\Models\OidcProvider;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Finds or creates the user an OIDC sign-in belongs to. A provider with email
 * domains turns away any other address first, on every sign-in, including
 * people linked before the domains were set.
 *
 * 1. A user already linked to the provider's subject signs in.
 * 2. Otherwise a user whose email matches is linked, but only when the
 *    provider marks the email verified or is trusted for emails, so nobody
 *    can take over an account by registering its address at the provider.
 * 3. Otherwise, when the provider creates accounts (its switch is on and it has
 *    email domains), a new organization Member is created with no workspace access.
 */
class OidcSignIn
{
    /**
     * @param  array<string, mixed>  $claims
     *
     * @throws RuntimeException with a message to show on the sign-in page
     */
    public function resolveUser(OidcProvider $provider, array $claims): User
    {
        $subject = (string) $claims['sub'];
        $email = is_string($claims['email'] ?? null) ? Str::lower(trim($claims['email'])) : null;
        $emailTrusted = $email && (in_array($claims['email_verified'] ?? false, [true, 'true'], true) || $provider->trust_email);

        if (! $provider->acceptsEmail($email)) {
            throw new RuntimeException(__('oidc.name_doesn_t_accept_this_email', ['name' => $provider->name]));
        }

        $identity = OidcIdentity::query()->with('user')->where('oidc_provider_id', $provider->id)->where('subject', $subject)->first();

        $user = $identity->user ?? DB::transaction(fn () => $this->linkOrCreate($provider, $subject, $email, $emailTrusted, $claims));

        if (! $user->isActiveIn($provider->organization_id)) {
            throw new RuntimeException(__('oidc.this_account_has_been_deactivated'));
        }

        OidcIdentity::query()->updateOrCreate(
            ['oidc_provider_id' => $provider->id, 'subject' => $subject],
            ['user_id' => $user->id, 'email' => $email, 'last_signed_in_at' => now()],
        );

        return $user;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    protected function linkOrCreate(OidcProvider $provider, string $subject, ?string $email, bool $emailTrusted, array $claims): User
    {
        $existing = $email ? User::query()->whereRaw('lower(email) = ?', [$email])->first() : null;

        if ($existing) {
            if (! $emailTrusted) {
                throw new RuntimeException(__('oidc.name_has_not_verified_this', ['name' => $provider->name]));
            }

            Audit::record('auth.oidc_linked', $existing, metadata: ['provider' => $provider->name], label: $existing->email, actor: $existing, organization: $provider->organization);

            return $existing;
        }

        if (! $provider->createsAccounts()) {
            throw new RuntimeException(__('oidc.there_is_no_account_for'));
        }

        if (! $email) {
            throw new RuntimeException(__('oidc.name_did_not_share_an', ['name' => $provider->name]));
        }

        $user = User::create([
            'name' => is_string($claims['name'] ?? null) && $claims['name'] !== '' ? $claims['name'] : Str::before($email, '@'),
            'email' => $email,
            'password' => Str::password(40),
        ]);

        if ($emailTrusted) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->organizations()->attach($provider->organization_id, ['role' => OrgRole::Member->value]);

        Audit::record('auth.oidc_registered', $user, metadata: ['provider' => $provider->name], label: $user->email, actor: $user, organization: $provider->organization);

        return $user;
    }
}
