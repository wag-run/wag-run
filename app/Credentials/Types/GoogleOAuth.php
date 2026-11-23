<?php

namespace App\Credentials\Types;

use App\Credentials\OAuthCredentialType;
use Illuminate\Support\Facades\Http;

/**
 * A Google account connected through the install's Google OAuth client, set
 * up with GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET. It asks for offline
 * access, so the connection keeps working after the hour an access token
 * lasts.
 */
class GoogleOAuth extends OAuthCredentialType
{
    public function key(): string
    {
        return 'google';
    }

    public function provider(): string
    {
        return 'google';
    }

    public function label(): string
    {
        return __('credentials.google.label');
    }

    public function description(): string
    {
        return __('credentials.google.description');
    }

    public function fields(): array
    {
        return [];
    }

    public function authorizeUrl(array $config): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth';
    }

    public function tokenUrl(array $config): string
    {
        return 'https://oauth2.googleapis.com/token';
    }

    public function clientId(array $config): ?string
    {
        return config('services.google.client_id');
    }

    public function clientSecret(array $config): ?string
    {
        return config('services.google.client_secret');
    }

    public function scopes(array $config): array
    {
        return ['openid', 'email', 'https://www.googleapis.com/auth/spreadsheets'];
    }

    public function authorizeParameters(): array
    {
        return ['access_type' => 'offline', 'prompt' => 'consent'];
    }

    public function environmentKeys(): array
    {
        return ['GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET'];
    }

    public function accountName(string $accessToken): ?string
    {
        $response = Http::withToken($accessToken)->acceptJson()->get('https://openidconnect.googleapis.com/v1/userinfo');

        return $response->successful() ? $response->json('email') : null;
    }
}
