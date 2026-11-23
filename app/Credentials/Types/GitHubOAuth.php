<?php

namespace App\Credentials\Types;

use App\Credentials\OAuthCredentialType;
use Illuminate\Support\Facades\Http;

/**
 * A GitHub account connected through the install's GitHub OAuth app, set up
 * with GITHUB_CLIENT_ID and GITHUB_CLIENT_SECRET.
 *
 * It asks for "repo", to read and change repositories (tags, releases,
 * issues and Actions), and "admin:repo_hook", so webhook triggers can
 * register themselves on a repository.
 */
class GitHubOAuth extends OAuthCredentialType
{
    public const API = 'https://api.github.com';

    public function key(): string
    {
        return 'github';
    }

    public function provider(): string
    {
        return 'github';
    }

    public function label(): string
    {
        return __('credentials.github.label');
    }

    public function description(): string
    {
        return __('credentials.github.description');
    }

    public function fields(): array
    {
        return [];
    }

    public function authorizeUrl(array $config): string
    {
        return 'https://github.com/login/oauth/authorize';
    }

    public function tokenUrl(array $config): string
    {
        return 'https://github.com/login/oauth/access_token';
    }

    public function clientId(array $config): ?string
    {
        return config('services.github.client_id');
    }

    public function clientSecret(array $config): ?string
    {
        return config('services.github.client_secret');
    }

    public function scopes(array $config): array
    {
        return ['repo', 'admin:repo_hook'];
    }

    public function environmentKeys(): array
    {
        return ['GITHUB_CLIENT_ID', 'GITHUB_CLIENT_SECRET'];
    }

    public function accountName(string $accessToken): ?string
    {
        $response = Http::withToken($accessToken)->acceptJson()->get(self::API.'/user');

        return $response->successful() ? $response->json('login') : null;
    }
}
