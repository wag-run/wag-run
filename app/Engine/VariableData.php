<?php

namespace App\Engine;

use App\Engine\Templates\DataBag;
use App\Engine\Templates\LazyData;
use App\Models\Execution;
use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * A run's workflow variables as expressions read them: vars.<name>. The
 * values are loaded the first time a step reads one, so a step sees what
 * earlier steps of the run wrote, and a step that reads none pays nothing.
 * A variable that isn't declared reads as null.
 *
 * @implements ArrayAccess<mixed, mixed>
 * @implements IteratorAggregate<array-key, mixed>
 */
final class VariableData implements ArrayAccess, Countable, IteratorAggregate, LazyData
{
    /**
     * @var array<string, mixed>|null
     */
    private ?array $values = null;

    public function __construct(private readonly ?Execution $execution) {}

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
        return $this->values ??= $this->execution ? app(RunVariables::class)->all($this->execution) : [];
    }
}
