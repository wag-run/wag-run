<?php

namespace App\Services;

use App\Enums\InstallRole;
use App\Models\OidcIdentity;
use App\Models\OidcProvider;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Finds or creates the user an OIDC sign-in belongs to.
 *
 * 1. A user already linked to the provider's subject signs in.
 * 2. Otherwise a user whose email matches is linked, but only when the
 *    provider marks the email verified or is trusted for emails, so nobody
 *    can take over an account by registering its address at the provider.
 * 3. Otherwise, when the provider allows registration, a new install Member
 *    is created with no workspace access.
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

        $identity = OidcIdentity::query()->with('user')->where('oidc_provider_id', $provider->id)->where('subject', $subject)->first();

        $user = $identity->user ?? DB::transaction(fn () => $this->linkOrCreate($provider, $subject, $email, $emailTrusted, $claims));

        if (! $user->organizationMembership() || $user->isDeactivated()) {
            throw new RuntimeException(__('This account has been deactivated. Ask an organization admin to reactivate it.'));
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
                throw new RuntimeException(__(':name has not verified this email address, so it cannot be linked to the existing account. Sign in with your password instead.', ['name' => $provider->name]));
            }

            Audit::record('auth.oidc_linked', $existing, metadata: ['provider' => $provider->name], label: $existing->email, actor: $existing);

            return $existing;
        }

        if (! $provider->allow_registration) {
            throw new RuntimeException(__('There is no account for this sign-in. Ask an admin to invite you.'));
        }

        if (! $email) {
            throw new RuntimeException(__(':name did not share an email address, which is needed to create an account.', ['name' => $provider->name]));
        }

        $user = User::create([
            'name' => is_string($claims['name'] ?? null) && $claims['name'] !== '' ? $claims['name'] : Str::before($email, '@'),
            'email' => $email,
            'password' => Str::password(40),
        ]);

        if ($emailTrusted) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $user->organizations()->attach($provider->organization_id, ['role' => InstallRole::Member->value]);

        Audit::record('auth.oidc_registered', $user, metadata: ['provider' => $provider->name], label: $user->email, actor: $user);

        return $user;
    }
}
