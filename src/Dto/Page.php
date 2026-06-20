<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use ArrayIterator;
use Countable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use Traversable;

/**
 * A single page of a paginated result set.
 *
 * @template T
 *
 * @implements IteratorAggregate<int, T>
 */
final readonly class Page extends Dto implements Countable, IteratorAggregate
{
    /**
     * @param  list<T>  $items
     */
    public function __construct(
        public array $items,
        public int $perPage,
        public int $page,
        public bool $hasMore,
        public ?string $nextCursor = null,
    ) {}

    /** @return Collection<int, T> */
    public function collect(): Collection
    {
        return new Collection($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }

    /** @return T|null */
    public function first(): mixed
    {
        return $this->items[0] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return Traversable<int, T> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
