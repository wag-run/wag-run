<?php

namespace Database\Factories;

use App\Models\StoreEntry;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreEntry>
 */
class StoreEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'namespace' => '',
            'key' => fake()->unique()->regexify('[a-z]{6}-[0-9]{4}'),
            'value' => json_encode(fake()->word()),
            'expires_at' => null,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }
}
