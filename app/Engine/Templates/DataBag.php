<?php

namespace App\Engine\Templates;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Read-only view of a run's data for ExpressionLanguage, so expressions can
 * use dot access (nodes.check.output.status) as well as brackets
 * (nodes['check']['output']['status']). Missing keys read as null.
 *
 * @implements ArrayAccess<array-key, mixed>
 * @implements IteratorAggregate<array-key, mixed>
 */
final class DataBag implements ArrayAccess, Countable, IteratorAggregate
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    private function __construct(private readonly array $data) {}

    public static function wrap(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return array_is_list($value) ? array_map(self::wrap(...), $value) : new self($value);
    }

    public static function unwrap(mixed $value): mixed
    {
        if ($value instanceof self) {
            return array_map(self::unwrap(...), $value->data);
        }

        if ($value instanceof LazyData) {
            return array_map(self::unwrap(...), $value->all());
        }

        return is_array($value) ? array_map(self::unwrap(...), $value) : $value;
    }

    public function __get(string $name): mixed
    {
        return self::wrap($this->data[$name] ?? null);
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return self::wrap($this->data[$offset] ?? null);
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}

    public function count(): int
    {
        return count($this->data);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_map(self::wrap(...), $this->data));
    }
}
