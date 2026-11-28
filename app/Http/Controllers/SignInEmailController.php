<?php

namespace App\Http\Controllers;

use App\Services\SignInOptions;
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

    public const ORGANIZATION_KEY = 'login.organization';

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
        $request->session()->forget(self::ORGANIZATION_KEY);

        return redirect()->route('login');
    }

    /**
     * Pick which of the email's organizations to sign in to.
     */
    public function chooseOrganization(Request $request, SignInOptions $options): RedirectResponse
    {
        $email = (string) $request->session()->get(self::SESSION_KEY);
        $organizationId = (string) $request->input('organization');

        abort_unless($email !== '' && $options->organizationsOf($email)->contains('id', $organizationId), 404);

        $request->session()->put(self::ORGANIZATION_KEY, $organizationId);

        return redirect()->route('login');
    }

    /**
     * "Not you?": forget the email and go back to the first step.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget([self::SESSION_KEY, self::ORGANIZATION_KEY]);

        return redirect()->route('login');
    }
}
