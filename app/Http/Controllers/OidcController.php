<?php

namespace App\Http\Controllers;

use App\Models\OidcProvider;
use App\Models\Organization;
use App\Services\OidcSignIn;
use App\Support\OidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Signs people in with an OIDC provider configured under Organization settings.
 */
class OidcController extends Controller
{
    protected const SESSION_KEY = 'oidc.request';

    public function __construct(protected OidcClient $client) {}

    public function redirect(Request $request, OidcProvider $provider): RedirectResponse
    {
        $this->ensureUsable($provider);

        try {
            $authorization = $this->client->authorizationRequest($provider, $this->callbackUrl($provider));
        } catch (RuntimeException $exception) {
            return $this->failed($exception->getMessage());
        }

        $request->session()->put(self::SESSION_KEY, [
            'provider' => $provider->id,
            'state' => $authorization['state'],
            'nonce' => $authorization['nonce'],
            'verifier' => $authorization['verifier'],
        ]);

        return redirect()->away($authorization['url']);
    }

    public function callback(Request $request, OidcProvider $provider, OidcSignIn $signIn): RedirectResponse
    {
        $this->ensureUsable($provider);

        $pending = $request->session()->pull(self::SESSION_KEY);

        if ($request->filled('error')) {
            return $this->failed(__(':name did not sign you in: :error', ['name' => $provider->name, 'error' => (string) ($request->query('error_description') ?: $request->query('error'))]));
        }

        if (! is_array($pending) || $pending['provider'] !== $provider->id || ! is_string($request->query('state')) || ! hash_equals($pending['state'], $request->query('state')) || ! $request->filled('code')) {
            return $this->failed(__('The sign-in expired or did not match. Try again.'));
        }

        try {
            $claims = $this->client->claims($provider, (string) $request->query('code'), $this->callbackUrl($provider), $pending['verifier'], $pending['nonce']);
            $user = $signIn->resolveUser($provider, $claims);
        } catch (RuntimeException $exception) {
            return $this->failed($exception->getMessage());
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    protected function ensureUsable(OidcProvider $provider): void
    {
        abort_unless($provider->enabled && $provider->organization_id === Organization::current()?->id, 404);
    }

    protected function callbackUrl(OidcProvider $provider): string
    {
        return route('oidc.callback', $provider);
    }

    protected function failed(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
