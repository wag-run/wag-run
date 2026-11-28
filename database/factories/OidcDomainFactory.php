<?php

namespace Database\Factories;

use App\Models\OidcDomain;
use App\Models\OidcProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OidcDomain>
 */
class OidcDomainFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'oidc_provider_id' => OidcProvider::factory(),
            'organization_id' => fn (array $attributes) => OidcProvider::query()->whereKey($attributes['oidc_provider_id'])->value('organization_id'),
            'domain' => fake()->unique()->domainName(),
        ];
    }
}
