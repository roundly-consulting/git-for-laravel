<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Query;

use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use RoundlyConsulting\Git\Dto\Page;

/**
 * Lazy, chainable query that translates typed filters into a provider request.
 *
 * @template T
 */
abstract class Query
{
    protected int $perPage = 30;

    /** @var array<string, scalar> */
    protected array $filters = [];

    /**
     * @param  Closure(array<string, scalar>, int, int): Page<T>  $fetcher
     */
    public function __construct(
        protected readonly Closure $fetcher,
    ) {}

    public function perPage(int $perPage): static
    {
        $this->perPage = $perPage;

        return $this;
    }

    /** @return Page<T> */
    public function get(int $page = 1): Page
    {
        return ($this->fetcher)($this->filters, $page, $this->perPage);
    }

    /** @return Collection<int, T> */
    public function collect(int $page = 1): Collection
    {
        return $this->get($page)->collect();
    }

    /** @return T|null */
    public function first(): mixed
    {
        return $this->get()->first();
    }

    /** @return LazyCollection<int, T> */
    public function lazy(): LazyCollection
    {
        return LazyCollection::make(function () {
            $page = 1;

            do {
                $result = $this->get($page);

                foreach ($result->items as $item) {
                    yield $item;
                }

                $page++;
            } while ($result->hasMore);
        });
    }
}
