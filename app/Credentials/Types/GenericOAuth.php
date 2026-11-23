<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\OAuthCredentialType;

/**
 * Any OAuth 2.0 provider, with the app's details filled in on the setting.
 * HTTP calls send its access token as a bearer token.
 */
class GenericOAuth extends OAuthCredentialType
{
    public function key(): string
    {
        return 'oauth2';
    }

    public function provider(): string
    {
        return 'oauth2';
    }

    public function label(): string
    {
        return __('credentials.oauth2.label');
    }

    public function description(): string
    {
        return __('credentials.oauth2.description');
    }

    public function fields(): array
    {
        return [
            CredentialField::url('authorize_url', __('credentials.oauth2.authorize_url'), required: true),
            CredentialField::url('token_url', __('credentials.oauth2.token_url'), required: true),
            CredentialField::text('client_id', __('credentials.oauth2.client_id')),
            CredentialField::secret('client_secret', __('credentials.oauth2.client_secret')),
            CredentialField::text('scope', __('credentials.oauth2.scopes'), required: false, help: __('credentials.oauth2.separated_by_spaces')),
        ];
    }

    public function authorizeUrl(array $config): string
    {
        return (string) ($config['authorize_url'] ?? '');
    }

    public function tokenUrl(array $config): string
    {
        return (string) ($config['token_url'] ?? '');
    }

    public function clientId(array $config): ?string
    {
        return $config['client_id'] ?? null;
    }

    public function clientSecret(array $config): ?string
    {
        return $config['client_secret'] ?? null;
    }

    public function scopes(array $config): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', (string) ($config['scope'] ?? '')) ?: []));
    }

    public function hasUserUrls(): bool
    {
        return true;
    }

    public function accountName(string $accessToken): ?string
    {
        return null;
    }
}
