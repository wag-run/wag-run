<?php

namespace App\Listeners;

use App\Models\OidcProvider;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Records each sign-in in the organization's audit log. A session restored
 * from a "remember me" cookie is not a new sign-in, so it is not recorded,
 * and nothing is recorded before the install has an organization.
 */
class RecordSignIn
{
    public function handle(Login $event): void
    {
        $guard = Auth::guard($event->guard);

        if (! $event->user instanceof User
            || ($guard instanceof SessionGuard && $guard->viaRemember())
            || ! Organization::current()) {
            return;
        }

        Audit::record('auth.signed_in', $event->user, metadata: $this->method(), label: $event->user->email, actor: $event->user);
    }

    /**
     * How the user signed in, from the route that signed them in.
     *
     * @return array{method?: string, provider?: string}
     */
    protected function method(): array
    {
        $provider = Request::route('provider');

        return array_filter(match (Request::route()?->getName()) {
            'login.store' => ['method' => 'password'],
            'two-factor.login.store' => ['method' => 'two_factor'],
            'passkey.login' => ['method' => 'passkey'],
            'register.store' => ['method' => 'registration'],
            'oidc.callback' => ['method' => 'oidc', 'provider' => $provider instanceof OidcProvider ? $provider->name : null],
            default => [],
        });
    }
}
