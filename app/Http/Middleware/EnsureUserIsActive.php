<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a user whom an organization admin has deactivated.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isDeactivated()) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return $request->expectsJson()
                ? response()->json(['message' => __('auth.session.this_account_has_been_deactivated')], 403)
                : redirect()->route('login')->withErrors(['email' => __('auth.session.this_account_has_been_deactivated')]);
        }

        return $next($request);
    }
}
