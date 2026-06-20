<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Query;

use Carbon\CarbonInterface;
use RoundlyConsulting\Git\Dto\Commit;

/**
 * @extends Query<Commit>
 */
final class CommitQuery extends Query
{
    public function branch(string $branch): self
    {
        $this->filters['branch'] = $branch;

        return $this;
    }

    public function author(string $author): self
    {
        $this->filters['author'] = $author;

        return $this;
    }

    public function path(string $path): self
    {
        $this->filters['path'] = $path;

        return $this;
    }

    public function since(CarbonInterface|string $since): self
    {
        $this->filters['since'] = $since instanceof CarbonInterface ? $since->toIso8601String() : $since;

        return $this;
    }

    public function until(CarbonInterface|string $until): self
    {
        $this->filters['until'] = $until instanceof CarbonInterface ? $until->toIso8601String() : $until;

        return $this;
    }
}
