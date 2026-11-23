<?php

namespace Database\Factories;

use App\Models\Credential;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Credential>
 */
class CredentialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'key' => fake()->unique()->regexify('[a-z]{6}_[a-z]{4}'),
            'type' => 'api_token',
            'config' => [
                'header' => 'Authorization',
                'prefix' => 'Bearer',
                'token' => fake()->sha256(),
            ],
            'description' => null,
        ];
    }

    public function httpBasic(): static
    {
        return $this->state(fn () => [
            'type' => 'http_basic',
            'config' => ['username' => fake()->userName(), 'password' => fake()->password(12)],
        ]);
    }

    public function smtp(): static
    {
        return $this->state(fn () => [
            'type' => 'smtp',
            'config' => [
                'host' => 'smtp.'.fake()->domainName(),
                'port' => '587',
                'encryption' => 'tls',
                'username' => fake()->userName(),
                'password' => fake()->password(12),
                'from_address' => fake()->safeEmail(),
                'from_name' => fake()->company(),
            ],
        ]);
    }

    public function chatWebhook(string $service = 'slack', string $url = 'https://hooks.example.com/services/T000/B000/secret'): static
    {
        return $this->state(fn () => [
            'type' => 'chat_webhook',
            'config' => ['service' => $service, 'url' => $url],
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    public function s3(array $overrides = []): static
    {
        return $this->state(fn () => [
            'type' => 's3',
            'config' => [
                'access_key_id' => 'AKIAEXAMPLE',
                'secret_access_key' => 'secret-'.fake()->sha1(),
                'region' => 'us-east-1',
                'bucket' => 'backups',
                'endpoint' => '',
                'path_style' => 'no',
                ...$overrides,
            ],
        ]);
    }

    public function aiProvider(string $provider = 'anthropic'): static
    {
        return $this->state(fn () => [
            'type' => 'ai_provider',
            'config' => ['provider' => $provider, 'api_key' => 'sk-'.fake()->sha256(), 'base_url' => null],
        ]);
    }

    /**
     * An OAuth setting already connected, for "github", "google" or "oauth2".
     *
     * @param  array<string, mixed>  $overrides
     */
    public function oauth(string $type = 'github', array $overrides = []): static
    {
        return $this->state(fn () => [
            'type' => $type,
            'config' => [
                'access_token' => 'gho_access',
                'refresh_token' => null,
                'expires_at' => null,
                'scopes' => null,
                'account' => 'octocat',
                ...$overrides,
            ],
        ]);
    }
}
