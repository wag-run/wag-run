<?php

namespace App\Engine;

use App\Engine\Templates\DataBag;
use App\Engine\Templates\LazyData;
use App\Models\StoreEntry;
use App\Models\Workspace;
use App\Services\DataStore;
use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * The workspace's data store as expressions read it: store.<key> is a
 * single entry's value, store.<key>[item] one item of a collection, and
 * store.<key> alone a whole collection. Nothing is loaded until it is read,
 * and each read is kept for the step, so a step sees what earlier steps of
 * the run wrote. An entry or item that doesn't exist reads as null.
 *
 * Given an entry, it stands for that collection's items; otherwise for the
 * workspace's entries.
 *
 * @implements ArrayAccess<mixed, mixed>
 * @implements IteratorAggregate<array-key, mixed>
 */
final class StoreData implements ArrayAccess, Countable, IteratorAggregate, LazyData
{
    /**
     * @var array<string, mixed> what each key read as, wrapped for expressions
     */
    private array $read = [];

    /**
     * @var array<array-key, mixed>|null
     */
    private ?array $all = null;

    public function __construct(
        private readonly Workspace $workspace,
        private readonly DataStore $store,
        private readonly ?StoreEntry $collection = null,
    ) {}

    public function __get(string $name): mixed
    {
        return $this->offsetGet($name);
    }

    public function __isset(string $name): bool
    {
        return $this->offsetExists($name);
    }

    public function offsetExists(mixed $offset): bool
    {
        return $this->offsetGet($offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (! is_scalar($offset) || (string) $offset === '') {
            return null;
        }

        $key = (string) $offset;

        if (! array_key_exists($key, $this->read)) {
            $this->read[$key] = $this->collection ? DataBag::wrap($this->store->read($this->collection, $key)) : $this->entry($key);
        }

        return $this->read[$key];
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}

    public function count(): int
    {
        return $this->collection ? $this->collection->values()->count() : $this->workspace->storeEntries()->count();
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_map(DataBag::wrap(...), $this->all()));
    }

    public function all(): array
    {
        return $this->all ??= $this->collection
            ? $this->store->items($this->collection)
            : $this->workspace->storeEntries()->orderBy('key')->get()->mapWithKeys(fn (StoreEntry $entry) => [$entry->key => $this->store->read($entry)])->all();
    }

    private function entry(string $key): mixed
    {
        $entry = $this->store->entry($this->workspace, $key);

        return match (true) {
            $entry === null => null,
            $entry->is_collection => new self($this->workspace, $this->store, $entry),
            default => DataBag::wrap($this->store->read($entry)),
        };
    }
}
