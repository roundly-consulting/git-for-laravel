<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Batch;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use RoundlyConsulting\Git\Dto\Dto;
use RoundlyConsulting\Git\Exceptions\BatchRequestException;
use Traversable;

/**
 * The keyed outcome of a concurrent batch fetch, separating successes from
 * per-key failures.
 *
 * @template T
 *
 * @implements IteratorAggregate<string, T>
 */
final readonly class BatchResult extends Dto implements Countable, IteratorAggregate
{
    /**
     * @param  array<string, T>  $results
     * @param  array<string, BatchError>  $errors
     */
    public function __construct(
        public array $results,
        public array $errors,
    ) {}

    public function successful(): bool
    {
        return $this->errors === [];
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    /** @return array<string, T> */
    public function results(): array
    {
        return $this->results;
    }

    /** @return array<string, BatchError> */
    public function errors(): array
    {
        return $this->errors;
    }

    /** @return T|null */
    public function get(string $key): mixed
    {
        return $this->results[$key] ?? null;
    }

    /** @return self<T> */
    public function throwOnError(): self
    {
        if ($this->failed()) {
            throw new BatchRequestException($this->errors);
        }

        return $this;
    }

    public function count(): int
    {
        return count($this->results);
    }

    /** @return Traversable<string, T> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->results);
    }
}
