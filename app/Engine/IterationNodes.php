<?php

namespace App\Engine;

use App\Engine\Templates\DataBag;
use App\Engine\Templates\LazyData;
use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/**
 * The nodes of one loop iteration as expressions read them: a finished
 * loop's last, one entry of its results, or loop.previous. Each node is
 * there by its id, in the same shape as nodes.<id>.
 *
 * The iteration's steps are loaded the first time an expression reads
 * into it, so a step that reads none of it pays nothing. Being an object,
 * it counts as true in a condition even when the iteration ran no node;
 * empty() tells the two apart.
 *
 * @implements ArrayAccess<mixed, mixed>
 * @implements IteratorAggregate<array-key, mixed>
 */
final class IterationNodes implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable, LazyData
{
    /**
     * @var array<string, array{output: mixed, port: string|null}>|null
     */
    private ?array $nodes = null;

    /**
     * @param  Closure(): array<string, array{output: mixed, port: string|null}>  $load  the iteration's nodes, by node id
     */
    public function __construct(private readonly Closure $load) {}

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
        return is_scalar($offset) && isset($this->all()[(string) $offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_scalar($offset) ? DataBag::wrap($this->all()[(string) $offset] ?? null) : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}

    public function count(): int
    {
        return count($this->all());
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_map(DataBag::wrap(...), $this->all()));
    }

    public function all(): array
    {
        return $this->nodes ??= ($this->load)();
    }

    /**
     * The plain values, should one of these ever be saved without being
     * read through an expression first.
     */
    public function jsonSerialize(): mixed
    {
        return DataBag::unwrap($this);
    }
}
