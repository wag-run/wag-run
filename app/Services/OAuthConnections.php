<?php

namespace App\Services;

use App\Credentials\OAuthCredentialType;
use App\Models\Credential;
use App\Models\User;
use App\Support\AddressAllowlist;
use App\Support\Audit;
use App\Support\NetworkGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Connects OAuth authentication settings and keeps their tokens fresh.
 *
 * Connecting is the authorization code flow with PKCE. The setting is saved
 * first, so the session only holds the state of the attempt: which setting,
 * who started it and the PKCE verifier. Client secrets never leave the
 * setting or the environment.
 */
class OAuthConnections
{
    protected const SESSION_KEY = 'oauth_connections';

    /**
     * Seconds before an access token expires that it counts as expired.
     */
    protected const EXPIRY_MARGIN = 60;

    public function __construct(protected NetworkGuard $guard) {}

    /**
     * Start connecting $credential: remember the attempt and return the
     * provider's consent page to send the person to.
     */
    public function begin(Credential $credential, User $user): string
    {
        $type = $this->typeOf($credential);
        $state = Str::random(40);
        $verifier = Str::random(64);

        session()->put(self::SESSION_KEY.'.'.$state, [
            'credential_id' => $credential->id,
            'user_id' => $user->id,
            'verifier' => $verifier,
            'expires_at' => now()->addMinutes(10)->getTimestamp(),
        ]);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $type->clientId($credential->config),
            'redirect_uri' => $type->callbackUrl(),
            'scope' => implode(' ', $type->scopes($credential->config)),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            ...$type->authorizeParameters(),
        ]);
        $url = $type->authorizeUrl($credential->config);

        return $url.(str_contains($url, '?') ? '&' : '?').$query;
    }

    /**
     * The setting a callback with this state is for, if the attempt is still
     * open, so a failure can send the person back to its workspace.
     */
    public function pendingCredential(string $state): ?Credential
    {
        $pending = session()->get(self::SESSION_KEY.'.'.$state);

        return is_array($pending) ? Credential::query()->whereKey($pending['credential_id'])->first() : null;
    }

    /**
     * Finish connecting: check the provider's callback, swap its code for
     * tokens and save them on the setting.
     *
     * @throws RuntimeException when the attempt is unknown or expired, the person declined, or the provider refused the code
     */
    public function complete(string $typeKey, Request $request, User $user): Credential
    {
        $pending = session()->pull(self::SESSION_KEY.'.'.$request->query('state', ''));
        $credential = is_array($pending) ? Credential::query()->whereKey($pending['credential_id'])->first() : null;

        if (! $credential || $pending['user_id'] !== $user->id || $pending['expires_at'] < now()->getTimestamp() || $credential->type !== $typeKey) {
            throw new RuntimeException(__('oauth.connection_attempt_expired'));
        }

        if (filled($request->query('error'))) {
            throw new RuntimeException(__('oauth.provider_declined', ['message' => (string) ($request->query('error_description') ?: $request->query('error'))]));
        }

        $type = $this->typeOf($credential);
        $tokens = $this->requestTokens($credential, [
            'grant_type' => 'authorization_code',
            'code' => (string) $request->query('code', ''),
            'redirect_uri' => $type->callbackUrl(),
            'code_verifier' => $pending['verifier'],
        ]);

        try {
            $account = $type->accountName($tokens['access_token']);
        } catch (Throwable) {
            $account = null;
        }

        $credential->config = [...$credential->config, ...$this->connection($tokens, null), 'account' => $account];
        $credential->save();

        Audit::record('credential.connected', $credential, $credential->workspace, ['type' => $credential->type, 'account' => $account]);

        return $credential;
    }

    /**
     * A working access token for a connected setting, refreshed first when
     * it has expired or is about to.
     *
     * @throws RuntimeException when the setting isn't connected or the refresh fails
     */
    public function accessToken(Credential $credential): string
    {
        if (! OAuthCredentialType::isConnected($credential)) {
            throw new RuntimeException(__('oauth.not_connected', ['key' => $credential->key]));
        }

        if ($this->expiring($credential) && filled($credential->config['refresh_token'] ?? null)) {
            Cache::lock('oauth-refresh:'.$credential->id, 30)->block(15, function () use ($credential) {
                $credential->refresh();

                if ($this->expiring($credential)) {
                    $tokens = $this->requestTokens($credential, [
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $credential->config['refresh_token'],
                    ]);

                    $credential->config = [...$credential->config, ...$this->connection($tokens, $credential->config['refresh_token'])];
                    $credential->save();
                }
            });
        }

        return (string) $credential->config['access_token'];
    }

    protected function expiring(Credential $credential): bool
    {
        $expiresAt = $credential->config['expires_at'] ?? null;

        return is_int($expiresAt) && $expiresAt - self::EXPIRY_MARGIN <= now()->getTimestamp();
    }

    /**
     * @param  array<string, mixed>  $tokens  the provider's token response
     * @return array<string, mixed>
     */
    protected function connection(array $tokens, ?string $previousRefreshToken): array
    {
        $expiresIn = $tokens['expires_in'] ?? null;

        return [
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $previousRefreshToken,
            'expires_at' => is_numeric($expiresIn) ? now()->getTimestamp() + (int) $expiresIn : null,
            'scopes' => $tokens['scope'] ?? null,
        ];
    }

    /**
     * Ask the provider's token endpoint for tokens.
     *
     * @param  array<string, string>  $parameters
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    protected function requestTokens(Credential $credential, array $parameters): array
    {
        $type = $this->typeOf($credential);
        $url = $type->tokenUrl($credential->config);
        $request = Http::asForm()->acceptJson()->timeout(30);

        if ($type->hasUserUrls()) {
            $target = $this->guard->check($url, new AddressAllowlist($credential->workspace->internal_allowlist ?? []));
            $ip = str_contains($target['ip'], ':') ? "[{$target['ip']}]" : $target['ip'];
            $request->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$ip}"]]]);
        }

        try {
            $response = $request->post($url, [
                ...$parameters,
                'client_id' => (string) $type->clientId($credential->config),
                'client_secret' => (string) $type->clientSecret($credential->config),
            ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException(__('oauth.token_request_failed', ['message' => $e->getMessage()]));
        }

        $tokens = $response->json();

        if (! is_array($tokens) || ! is_string($tokens['access_token'] ?? null) || $tokens['access_token'] === '') {
            $message = is_array($tokens) ? ($tokens['error_description'] ?? $tokens['error'] ?? null) : null;

            throw new RuntimeException(__('oauth.token_request_failed', ['message' => $message ?? 'HTTP '.$response->status()]));
        }

        return $tokens;
    }

    protected function typeOf(Credential $credential): OAuthCredentialType
    {
        $type = $credential->credentialType();

        if (! $type instanceof OAuthCredentialType) {
            throw new RuntimeException(__('oauth.not_an_oauth_setting', ['key' => $credential->key]));
        }

        return $type;
    }
}
