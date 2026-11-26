<?php

namespace Database\Factories;

use App\Enums\ValueType;
use App\Models\StoreEntry;
use App\Models\Workspace;
use App\Services\DataStore;
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
            'key' => fake()->unique()->regexify('[a-z]{6}_[0-9]{4}'),
            'type' => ValueType::String,
            'is_collection' => false,
            'read_only' => false,
        ];
    }

    public function type(ValueType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }

    public function collection(): static
    {
        return $this->state(fn () => ['is_collection' => true]);
    }

    public function readOnly(): static
    {
        return $this->state(fn () => ['read_only' => true]);
    }

    /**
     * Give a single entry this value, or a collection these items by key.
     */
    public function holding(mixed $value): static
    {
        return $this->afterCreating(function (StoreEntry $entry) use ($value) {
            $store = app(DataStore::class);

            foreach ($entry->is_collection ? (array) $value : ['' => $value] as $itemKey => $item) {
                $store->put($entry, $item, $entry->is_collection ? (string) $itemKey : null);
            }
        });
    }

    public function configure(): static
    {
        return $this->afterCreating(function (StoreEntry $entry) {
            if (! $entry->is_collection && ! $entry->values()->exists()) {
                app(DataStore::class)->put($entry, $entry->type->emptyValue());
            }
        });
    }
}
