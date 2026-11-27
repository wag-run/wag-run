<?php

namespace App\Engine\Templates;

use ArrayAccess;
use ArrayIterator;
use Closure;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A list inside a run's data that is worked out the first time an
 * expression reads it, such as loop.results: every earlier pass of a While
 * loop. A step that never reads it pays nothing.
 *
 * @implements ArrayAccess<mixed, mixed>
 * @implements IteratorAggregate<int, mixed>
 */
final class LazyList implements ArrayAccess, Countable, IteratorAggregate, LazyData
{
    /**
     * @var list<mixed>|null
     */
    private ?array $values = null;

    /**
     * @param  Closure(): list<mixed>  $load
     */
    public function __construct(private readonly Closure $load) {}

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && isset($this->all()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_numeric($offset) ? DataBag::wrap($this->all()[(int) $offset] ?? null) : null;
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
        return $this->values ??= ($this->load)();
    }
}
