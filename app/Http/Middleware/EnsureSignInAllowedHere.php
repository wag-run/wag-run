<?php

namespace App\Http\Middleware;

use App\Http\Controllers\SignInEmailController;
use App\Listeners\RememberSignInMethod;
use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entering an organization checks its own rules against how the user signed
 * in: a password only where password sign-in is on, and a provider only if it's
 * one of the organization's enabled providers. Otherwise the user is signed out
 * and sent back to the sign-in page with their email, which then offers that
 * organization's ways in.
 */
class EnsureSignInAllowedHere
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $organization = Organization::current();

        if (! $user instanceof User || ! $organization || ! $request->hasSession() || $this->allows($organization, $request)) {
            return $next($request);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(SignInEmailController::SESSION_KEY, $user->email);

        $message = __('auth.login.name_asks_you_to_sign', ['name' => $organization->name]);

        return $request->expectsJson()
            ? response()->json(['message' => $message], 401)
            : redirect()->route('login')->with('status', $message);
    }

    protected function allows(Organization $organization, Request $request): bool
    {
        $signedInWith = $request->session()->get(RememberSignInMethod::SESSION_KEY, ['method' => 'password']);

        if (($signedInWith['method'] ?? 'password') === 'oidc') {
            return $organization->oidcProviders()->whereKey($signedInWith['provider_id'] ?? null)->where('enabled', true)->exists();
        }

        return $organization->password_sign_in;
    }
}
