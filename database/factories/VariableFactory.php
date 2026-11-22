<?php

namespace Database\Factories;

use App\Models\Variable;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Variable>
 */
class VariableFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'key' => fake()->unique()->regexify('[a-z]{6}_[a-z]{4}'),
            'value' => fake()->word(),
            'description' => null,
        ];
    }
}
