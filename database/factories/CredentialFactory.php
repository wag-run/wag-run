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

    public function aiProvider(string $provider = 'anthropic'): static
    {
        return $this->state(fn () => [
            'type' => 'ai_provider',
            'config' => ['provider' => $provider, 'api_key' => 'sk-'.fake()->sha256(), 'base_url' => null],
        ]);
    }
}
