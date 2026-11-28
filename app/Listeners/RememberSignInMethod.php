<?php

namespace App\Listeners;

use App\Models\OidcProvider;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Keeps in the session how the user signed in, so entering an organization
 * can check its own rules against it ({@see EnsureSignInAllowedHere}). A
 * session restored from a "remember me" cookie isn't a new sign-in, and is
 * treated as a password sign-in, which it started as.
 */
class RememberSignInMethod
{
    public const SESSION_KEY = 'auth.signed_in_with';

    public function handle(Login $event): void
    {
        $guard = Auth::guard($event->guard);

        if (! $event->user instanceof User || ! Request::hasSession() || ($guard instanceof SessionGuard && $guard->viaRemember())) {
            return;
        }

        $provider = Request::route('provider');

        Request::session()->put(self::SESSION_KEY, Request::route()?->getName() === 'oidc.callback' && $provider instanceof OidcProvider
            ? ['method' => 'oidc', 'provider_id' => $provider->id]
            : ['method' => 'password']);
    }
}
