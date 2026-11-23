<?php

namespace App\Credentials;

use App\Models\Credential;
use App\Services\OAuthConnections;
use Illuminate\Http\Client\PendingRequest;

/**
 * An authentication setting that connects to a provider through OAuth 2.0:
 * someone with access to the workspace approves wag.run at the provider,
 * and the setting keeps the tokens it gets back.
 *
 * Its config holds the fields people fill in (none for a provider whose app
 * is set up in the environment) plus the connection: access_token,
 * refresh_token, expires_at (a Unix time), scopes and account, the name of
 * the account that approved it.
 *
 * HTTP calls send the access token as a bearer token. Integration nodes get
 * a fresh one from {@see OAuthConnections::accessToken()}.
 */
abstract class OAuthCredentialType extends CredentialType implements HttpAuthenticator
{
    public const CONNECTION_KEYS = ['access_token', 'refresh_token', 'expires_at', 'scopes', 'account'];

    /**
     * Which integration this is, such as "github", for nodes that only work
     * with one provider.
     */
    abstract public function provider(): string;

    /**
     * @param  array<string, mixed>  $config
     */
    abstract public function authorizeUrl(array $config): string;

    /**
     * @param  array<string, mixed>  $config
     */
    abstract public function tokenUrl(array $config): string;

    /**
     * @param  array<string, mixed>  $config
     */
    abstract public function clientId(array $config): ?string;

    /**
     * @param  array<string, mixed>  $config
     */
    abstract public function clientSecret(array $config): ?string;

    /**
     * The scopes to ask for.
     *
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    abstract public function scopes(array $config): array;

    /**
     * The name of the account a new access token belongs to, to show on the
     * setting, or null when the provider doesn't say.
     */
    abstract public function accountName(string $accessToken): ?string;

    /**
     * Extra query parameters for the provider's consent page.
     *
     * @return array<string, string>
     */
    public function authorizeParameters(): array
    {
        return [];
    }

    /**
     * Whether the OAuth app is set up, so people can connect.
     *
     * @param  array<string, mixed>  $config
     */
    public function isConfigured(array $config = []): bool
    {
        return filled($this->clientId($config)) && filled($this->clientSecret($config));
    }

    /**
     * Whether the URLs in the config are the user's own, and so must pass
     * the network guard like an HTTP call's.
     */
    public function hasUserUrls(): bool
    {
        return false;
    }

    /**
     * Whether accounts are connected from a node's account field in the
     * editor, rather than added on the Authentication page: true for a
     * provider whose app is set up in the environment.
     */
    public function connectsFromNodes(): bool
    {
        return ! $this->hasUserUrls();
    }

    /**
     * The environment variables that set up this provider's app, to tell
     * an administrator what is missing.
     *
     * @return list<string>
     */
    public function environmentKeys(): array
    {
        return [];
    }

    public function callbackUrl(): string
    {
        return route('integrations.oauth.callback', $this->key());
    }

    public static function isConnected(Credential $credential): bool
    {
        return filled($credential->config['access_token'] ?? null);
    }

    public function authenticate(PendingRequest $request, array $config): PendingRequest
    {
        return $request->withToken((string) ($config['access_token'] ?? ''));
    }

    public function secretValues(array $config): array
    {
        return [
            ...parent::secretValues($config),
            ...array_values(array_filter([$config['access_token'] ?? null, $config['refresh_token'] ?? null], fn (mixed $value) => is_string($value) && $value !== '')),
        ];
    }

    public function keptOnSave(array $config): array
    {
        return array_intersect_key($config, array_flip(self::CONNECTION_KEYS));
    }
}
