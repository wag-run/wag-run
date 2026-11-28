<?php

namespace App\Http\Middleware;

use App\Enums\TwoFactorPolicy;
use App\Listeners\RememberSignInMethod;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * In an organization that requires two-factor authentication, someone who
 * signed in with a password and hasn't set it up is sent to Security to set it
 * up, and can't reach anything else until they do. Sign-ins through a provider
 * are left alone, since the provider runs its own.
 */
class EnsureTwoFactorWhereRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organization = Organization::current();

        if (! $user instanceof User || ! $organization || ! $request->hasSession()
            || $organization->twoFactorPolicy() !== TwoFactorPolicy::Required
            || ! $organization->password_sign_in
            || ($request->session()->get(RememberSignInMethod::SESSION_KEY)['method'] ?? 'password') !== 'password'
            || $user->hasTwoFactorSetUp()
            || $this->isSetUpOrSignOut($request)) {
            return $next($request);
        }

        $message = __('settings.security.name_requires_two_factor_authentication', ['name' => $organization->name]);

        return $request->expectsJson()
            ? response()->json(['message' => $message], 403)
            : redirect()->route('security.edit');
    }

    /**
     * The requests someone setting it up still needs: the Security page and
     * its Livewire updates, and signing out.
     */
    protected function isSetUpOrSignOut(Request $request): bool
    {
        return $request->routeIs('security.edit', 'logout') || str_ends_with((string) $request->route()?->getName(), 'livewire.update');
    }
}
