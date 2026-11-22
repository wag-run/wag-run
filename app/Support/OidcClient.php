<?php

namespace App\Support;

use App\Models\OidcProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The OpenID Connect authorization code flow with PKCE, against a provider
 * found through its discovery document.
 *
 * The ID token comes straight from the provider's token endpoint over TLS,
 * so, as OpenID Connect Core 3.1.3.7 allows, its issuer is trusted from the
 * TLS connection instead of a signature check. Its issuer, audience, expiry
 * and nonce are still checked. This is why the issuer must use https (plain
 * http is accepted only for localhost, for local development).
 */
class OidcClient
{
    protected const CLOCK_SKEW_SECONDS = 120;

    /**
     * The provider's discovery document, cached for an hour.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the provider cannot be reached or its document is not valid
     */
    public function discover(OidcProvider $provider): array
    {
        static::assertSecureIssuer($provider->normalizedIssuer());

        return Cache::remember('oidc:discovery:'.sha1($provider->normalizedIssuer()), now()->addHour(), function () use ($provider) {
            try {
                $response = Http::timeout(10)->acceptJson()->get($provider->normalizedIssuer().'/.well-known/openid-configuration');
            } catch (ConnectionException) {
                throw new RuntimeException(__('oidc.could_not_reach_name', ['name' => $provider->name]));
            }

            $document = $response->successful() ? $response->json() : null;

            if (! is_array($document) || ! isset($document['authorization_endpoint'], $document['token_endpoint'], $document['issuer'])) {
                throw new RuntimeException(__('oidc.name_did_not_return_a', ['name' => $provider->name]));
            }

            if (rtrim((string) $document['issuer'], '/') !== $provider->normalizedIssuer()) {
                throw new RuntimeException(__('oidc.issuer_in_name_s_discovery', ['name' => $provider->name]));
            }

            return $document;
        });
    }

    /**
     * Where to send the user to sign in, plus the values to keep in the
     * session until they come back.
     *
     * @return array{url: string, state: string, nonce: string, verifier: string}
     */
    public function authorizationRequest(OidcProvider $provider, string $redirectUri): array
    {
        $document = $this->discover($provider);
        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $provider->client_id,
            'redirect_uri' => $redirectUri,
            'scope' => $this->scopes($provider),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => static::base64UrlEncode(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], arg_separator: '&', encoding_type: PHP_QUERY_RFC3986);

        $endpoint = (string) $document['authorization_endpoint'];

        return [
            'url' => $endpoint.(str_contains($endpoint, '?') ? '&' : '?').$query,
            'state' => $state,
            'nonce' => $nonce,
            'verifier' => $verifier,
        ];
    }

    /**
     * Exchange the authorization code for tokens and return the checked
     * claims about the user, completed from the userinfo endpoint when the
     * ID token leaves out their email or name.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException when the exchange fails or the ID token is not valid
     */
    public function claims(OidcProvider $provider, string $code, string $redirectUri, string $verifier, string $nonce): array
    {
        $document = $this->discover($provider);

        try {
            $response = Http::timeout(10)->acceptJson()->asForm()
                ->withBasicAuth(rawurlencode($provider->client_id), rawurlencode($provider->client_secret))
                ->post((string) $document['token_endpoint'], [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $redirectUri,
                    'code_verifier' => $verifier,
                ]);
        } catch (ConnectionException) {
            throw new RuntimeException(__('oidc.could_not_reach_name', ['name' => $provider->name]));
        }

        $tokens = $response->json();

        if (! $response->successful() || ! is_array($tokens) || ! is_string($tokens['id_token'] ?? null)) {
            throw new RuntimeException(__('oidc.name_did_not_accept_the', ['name' => $provider->name]));
        }

        $claims = $this->validatedIdToken($provider, $tokens['id_token'], $nonce);

        if ((! isset($claims['email']) || ! isset($claims['name'])) && isset($document['userinfo_endpoint']) && is_string($tokens['access_token'] ?? null)) {
            $claims = [...$this->userInfo((string) $document['userinfo_endpoint'], $tokens['access_token'], (string) $claims['sub']), ...array_filter($claims, fn (mixed $value) => $value !== null)];
        }

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatedIdToken(OidcProvider $provider, string $idToken, string $nonce): array
    {
        $parts = explode('.', $idToken);
        $claims = count($parts) === 3 ? json_decode(static::base64UrlDecode($parts[1]), true) : null;

        if (! is_array($claims) || ! is_string($claims['sub'] ?? null) || $claims['sub'] === '') {
            throw new RuntimeException(__('oidc.id_token_from_name_could', ['name' => $provider->name]));
        }

        $audiences = (array) ($claims['aud'] ?? []);
        $now = time();

        $problem = match (true) {
            rtrim((string) ($claims['iss'] ?? ''), '/') !== $provider->normalizedIssuer() => 'issuer',
            ! in_array($provider->client_id, $audiences, true) => 'audience',
            count($audiences) > 1 && ($claims['azp'] ?? null) !== $provider->client_id => 'authorized party',
            ! is_numeric($claims['exp'] ?? null) || (int) $claims['exp'] < $now - self::CLOCK_SKEW_SECONDS => 'expiry',
            is_numeric($claims['iat'] ?? null) && (int) $claims['iat'] > $now + self::CLOCK_SKEW_SECONDS => 'issue time',
            ! hash_equals($nonce, (string) ($claims['nonce'] ?? '')) => 'nonce',
            default => null,
        };

        if ($problem) {
            throw new RuntimeException(__('oidc.id_token_from_name_failed', ['name' => $provider->name, 'check' => $problem]));
        }

        return $claims;
    }

    /**
     * @return array<string, mixed>
     */
    protected function userInfo(string $endpoint, string $accessToken, string $subject): array
    {
        try {
            $info = Http::timeout(10)->acceptJson()->withToken($accessToken)->get($endpoint)->json();
        } catch (ConnectionException) {
            return [];
        }

        return is_array($info) && ($info['sub'] ?? null) === $subject ? $info : [];
    }

    protected function scopes(OidcProvider $provider): string
    {
        $scopes = preg_split('/[\s,]+/', $provider->scopes, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_unique(['openid', ...$scopes]));
    }

    /**
     * @throws RuntimeException when the issuer is not https (or http on localhost)
     */
    public static function assertSecureIssuer(string $issuer): void
    {
        $scheme = parse_url($issuer, PHP_URL_SCHEME);
        $host = parse_url($issuer, PHP_URL_HOST);

        if ($scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true))) {
            return;
        }

        throw new RuntimeException(__('oidc.issuer_must_be_an_https'));
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');

        return (string) base64_decode(str_pad($value, (int) (ceil(strlen($value) / 4) * 4), '='), true);
    }
}
