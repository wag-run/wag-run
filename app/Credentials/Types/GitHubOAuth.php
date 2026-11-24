<?php

namespace App\Credentials\Types;

use App\Credentials\CredentialField;
use App\Credentials\OAuthCredentialType;
use Illuminate\Support\Facades\Http;

/**
 * A GitHub account connected through OAuth: through the install's GitHub
 * OAuth app, set up with GITHUB_CLIENT_ID and GITHUB_CLIENT_SECRET, or
 * through the client ID and secret on the setting, which win when given.
 * Those can be a GitHub OAuth app's or a GitHub App's, which then acts as
 * the person who connects.
 *
 * It asks for "repo", to read and change repositories (tags, releases,
 * issues and Actions), and "admin:repo_hook", so webhook triggers can
 * register themselves on a repository. A GitHub App ignores scopes and
 * uses the permissions set on the app.
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
        return [
            CredentialField::text('client_id', __('credentials.oauth2.client_id'), required: false, help: __('credentials.github.client_id_help')),
            CredentialField::secret('client_secret', __('credentials.oauth2.client_secret'), required: false),
        ];
    }

    /**
     * The client ID and secret are needed only when the environment does
     * not set up an app.
     */
    public function rules(string $prefix = 'config'): array
    {
        $rules = parent::rules($prefix);

        if (! $this->isConfigured()) {
            $rules["{$prefix}.client_id"][0] = 'required';
            $rules["{$prefix}.client_secret"][0] = 'required';
        }

        return $rules;
    }

    /**
     * Straight to GitHub when the environment sets up an app; otherwise the
     * form asks for the app's client ID and secret first.
     */
    public function connectsWithoutDetails(): bool
    {
        return $this->isConfigured();
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
        return filled($config['client_id'] ?? null) ? (string) $config['client_id'] : config('services.github.client_id');
    }

    public function clientSecret(array $config): ?string
    {
        return filled($config['client_secret'] ?? null) ? (string) $config['client_secret'] : config('services.github.client_secret');
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
