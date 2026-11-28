<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The first step of signing in: the email address, kept in the session (never
 * the URL) while the sign-in page shows the ways in for it.
 */
class SignInEmailController extends Controller
{
    public const SESSION_KEY = 'login.email';

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $email = Str::lower(trim($validated['email']));
        $throttleKey = 'login-email:'.$email.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        RateLimiter::hit($throttleKey);

        $request->session()->put(self::SESSION_KEY, $email);

        return redirect()->route('login');
    }

    /**
     * "Not you?": forget the email and go back to the first step.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('login');
    }
}
