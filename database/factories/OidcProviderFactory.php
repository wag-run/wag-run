<?php

namespace Database\Factories;

use App\Models\OidcProvider;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OidcProvider>
 */
class OidcProviderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'organization_id' => Organization::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'issuer' => 'https://id.example.com',
            'client_id' => 'wag-run',
            'client_secret' => Str::random(32),
            'scopes' => 'openid email profile',
            'enabled' => true,
            'allow_registration' => false,
            'trust_email' => false,
        ];
    }

    public function allowingRegistration(): static
    {
        return $this->state(['allow_registration' => true]);
    }

    public function disabled(): static
    {
        return $this->state(['enabled' => false]);
    }
}
