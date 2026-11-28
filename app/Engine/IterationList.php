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
 * A finished loop's results as expressions read them: one entry per
 * iteration, each holding the nodes that ran in it (see IterationNodes).
 *
 * Nothing is loaded until an expression reads into the list. Reading one
 * entry (results[2]) loads that iteration; anything that needs the whole
 * list, such as column(), loads the loop once. How many entries there are
 * is known without loading any.
 *
 * @implements ArrayAccess<mixed, mixed>
 * @implements IteratorAggregate<int, mixed>
 */
final class IterationList implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable, LazyData
{
    /**
     * @var list<array<string, array{output: mixed, port: string|null}>>|null
     */
    private ?array $all = null;

    /**
     * @var array<int, array<string, array{output: mixed, port: string|null}>> the entries read one at a time
     */
    private array $read = [];

    /**
     * @param  int  $count  how many iterations the loop ran
     * @param  Closure(int): array<string, array{output: mixed, port: string|null}>  $one  the nodes of one iteration
     * @param  Closure(): list<array<string, array{output: mixed, port: string|null}>>  $every  the nodes of every iteration, in order
     */
    public function __construct(private readonly int $count, private readonly Closure $one, private readonly Closure $every) {}

    public function offsetExists(mixed $offset): bool
    {
        return $this->index($offset) !== null;
    }

    public function offsetGet(mixed $offset): mixed
    {
        $index = $this->index($offset);

        if ($index === null) {
            return null;
        }

        return DataBag::wrap($this->all[$index] ?? ($this->read[$index] ??= ($this->one)($index)));
    }

    public function offsetSet(mixed $offset, mixed $value): void {}

    public function offsetUnset(mixed $offset): void {}

    public function count(): int
    {
        return max(0, $this->count);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator(array_map(DataBag::wrap(...), $this->all()));
    }

    public function all(): array
    {
        return $this->all ??= ($this->every)();
    }

    /**
     * The plain values, should one of these ever be saved without being
     * read through an expression first.
     */
    public function jsonSerialize(): mixed
    {
        return DataBag::unwrap($this);
    }

    /**
     * The position an offset names, when the list has one there.
     */
    private function index(mixed $offset): ?int
    {
        $index = filter_var($offset, FILTER_VALIDATE_INT);

        return $index !== false && $index >= 0 && $index < $this->count ? $index : null;
    }
}
