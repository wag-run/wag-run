<?php

namespace App\Services;

use App\Enums\ValueOperation;
use App\Enums\ValueType;
use App\Models\StoreEntry;
use App\Models\StoreValue;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The workspace data store: typed entries that keep their values between
 * runs. People declare entries on the Data store page; workflows read them
 * through {{ store.<key> }} placeholders and change them with the Update
 * data store node. An entry is a single value or a collection of items.
 *
 * A change is one step, safe when runs overlap: a new value relies on the
 * unique key, and a change to an existing one locks its row.
 */
class DataStore
{
    public const KEY_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public const KEY_MAX_LENGTH = 100;

    public const ITEM_KEY_MAX_LENGTH = 191;

    public function entry(Workspace $workspace, string $key): ?StoreEntry
    {
        return StoreEntry::query()->where(['workspace_id' => $workspace->id, 'key' => $key])->first();
    }

    /**
     * Declare an entry. A single entry starts with $value, or its type's
     * empty value when none is given; a collection starts with no items.
     *
     * @throws RuntimeException when the value doesn't fit the type, is too large, or the workspace is full
     */
    public function create(Workspace $workspace, string $key, ValueType $type, bool $isCollection = false, mixed $value = null, bool $readOnly = false, ?string $description = null): StoreEntry
    {
        return DB::transaction(function () use ($workspace, $key, $type, $isCollection, $value, $readOnly, $description) {
            $entry = StoreEntry::create([
                'workspace_id' => $workspace->id,
                'key' => $key,
                'type' => $type,
                'is_collection' => $isCollection,
                'read_only' => $readOnly,
                'description' => $description,
            ]);

            if (! $isCollection) {
                $this->put($entry, $value);
            }

            return $entry;
        });
    }

    /**
     * A single entry's value, one item of a collection (null when nothing
     * is saved under the item key), or, with no item key, a collection's
     * items by key.
     */
    public function read(StoreEntry $entry, ?string $itemKey = null): mixed
    {
        if ($entry->is_collection && $itemKey === null) {
            return $this->items($entry);
        }

        $value = $this->values($entry, $entry->is_collection ? $itemKey : '')->first();

        return $value ? $value->decoded() : ($entry->is_collection ? null : $entry->type->emptyValue());
    }

    /**
     * @return array<array-key, mixed> a collection's items by key
     */
    public function items(StoreEntry $entry): array
    {
        return $entry->values()->orderBy('item_key')->pluck('value', 'item_key')
            ->map(fn (string $json) => json_decode($json, true))->all();
    }

    public function has(StoreEntry $entry, string $itemKey): bool
    {
        return $this->values($entry, $itemKey)->exists();
    }

    /**
     * Change a value from a workflow. A read-only entry refuses.
     *
     * @return array{before: mixed, after: mixed}
     *
     * @throws RuntimeException when the entry is read-only or the change can't be made, see apply()
     */
    public function write(StoreEntry $entry, ValueOperation $operation, mixed $operand, mixed $itemKey = null): array
    {
        if ($entry->read_only) {
            throw new RuntimeException(__('nodes.store.entry_is_read_only', ['key' => $entry->key]));
        }

        return $this->apply($entry, $operation, $operand, $itemKey);
    }

    /**
     * Set a value as a person does on the Data store page, read-only or
     * not. No value (null) sets the type's empty value.
     *
     * @throws RuntimeException when the value doesn't fit the type, is too large, or the workspace is full
     */
    public function put(StoreEntry $entry, mixed $value, mixed $itemKey = null): void
    {
        if ($value === null && $entry->type !== ValueType::DateTime) {
            $value = $entry->type->emptyValue();
        }

        $this->apply($entry, ValueOperation::Set, $value, $itemKey, allowEmpty: true);
    }

    /**
     * Remove one item of a collection.
     */
    public function forget(StoreEntry $entry, string $itemKey): bool
    {
        return $entry->is_collection && $this->values($entry, $itemKey)->delete() > 0;
    }

    /**
     * How many values the workspace holds, collection items included.
     */
    public function count(Workspace $workspace): int
    {
        return StoreValue::query()->where('workspace_id', $workspace->id)->count();
    }

    /**
     * @throws RuntimeException when the item key is empty or too long
     */
    public static function cleanItemKey(mixed $itemKey): string
    {
        $itemKey = is_scalar($itemKey) ? trim(is_bool($itemKey) ? ($itemKey ? 'true' : 'false') : (string) $itemKey) : '';

        if ($itemKey === '') {
            throw new RuntimeException(__('nodes.store.item_key_is_required'));
        }

        if (mb_strlen($itemKey) > self::ITEM_KEY_MAX_LENGTH) {
            throw new RuntimeException(__('nodes.store.item_key_too_long', ['max' => self::ITEM_KEY_MAX_LENGTH]));
        }

        return $itemKey;
    }

    /**
     * Apply an operation to a single entry's value or to one item of a
     * collection, creating the value when it isn't there yet.
     *
     * @param  bool  $allowEmpty  whether Set may store no value (null), as a person clearing a date does
     * @return array{before: mixed, after: mixed}
     *
     * @throws RuntimeException when the operation or value doesn't fit the type, the result is too large, or the workspace is full
     */
    protected function apply(StoreEntry $entry, ValueOperation $operation, mixed $operand, mixed $itemKey, bool $allowEmpty = false): array
    {
        $itemKey = $entry->is_collection ? self::cleanItemKey($itemKey) : '';
        $change = fn (mixed $current): mixed => $allowEmpty && $operand === null ? null : $operation->apply($entry->type, $current, $operand);

        return DB::transaction(function () use ($entry, $itemKey, $change) {
            if (! $this->values($entry, $itemKey)->exists()) {
                $after = $change(null);

                $created = StoreValue::query()->insertOrIgnore([[
                    'workspace_id' => $entry->workspace_id, 'store_entry_id' => $entry->id, 'item_key' => $itemKey,
                    'value' => $this->encode($entry->type, $after), 'created_at' => now(), 'updated_at' => now(),
                ]]) === 1;

                if ($created) {
                    $this->assertRoom($entry);

                    return ['before' => null, 'after' => $after];
                }
            }

            $value = $this->values($entry, $itemKey)->lockForUpdate()->firstOrFail();
            $before = $value->decoded();
            $after = $change($before);

            $value->forceFill(['value' => $this->encode($entry->type, $after)])->save();

            return ['before' => $before, 'after' => $after];
        }, 3);
    }

    /**
     * @return Builder<StoreValue>
     */
    protected function values(StoreEntry $entry, string $itemKey): Builder
    {
        return StoreValue::query()->where(['store_entry_id' => $entry->id, 'item_key' => $itemKey]);
    }

    /**
     * @throws RuntimeException when the workspace holds more values than it may
     */
    protected function assertRoom(StoreEntry $entry): void
    {
        $limit = (int) config('workflows.store.max_values');

        if (StoreValue::query()->where('workspace_id', $entry->workspace_id)->count() > $limit) {
            throw new RuntimeException(__('nodes.store.store_is_full', ['max' => number_format($limit)]));
        }
    }

    /**
     * @throws RuntimeException when the value can't be saved as JSON or is too large
     */
    protected function encode(ValueType $type, mixed $value): string
    {
        return $type->encode($value, (int) config('workflows.store.max_value_bytes'));
    }
}
