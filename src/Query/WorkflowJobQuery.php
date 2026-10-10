<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Query;

use InvalidArgumentException;
use RoundlyConsulting\Git\Dto\WorkflowJob;

/**
 * The jobs of one GitHub Actions run — of its LATEST attempt unless told otherwise.
 *
 * The page's `total` is GitHub's `total_count`, so `->perPage(1)->get()->total` answers
 * "did this run start any job" in one small request.
 *
 * @extends Query<WorkflowJob>
 */
final class WorkflowJobQuery extends Query
{
    /**
     * The jobs of every attempt, not just the latest (`filter=all`).
     *
     * @throws InvalidArgumentException when one attempt was already asked for
     */
    public function allAttempts(): self
    {
        if (isset($this->filters['attempt'])) {
            throw new InvalidArgumentException('Ask for every attempt or for one attempt, not both.');
        }

        $this->filters['filter'] = 'all';

        return $this;
    }

    /**
     * The jobs of one attempt (1 is the first run, 2 its first re-run, …).
     *
     * @throws InvalidArgumentException when below 1, or when every attempt was already asked for
     */
    public function attempt(int $attempt): self
    {
        if ($attempt < 1) {
            throw new InvalidArgumentException("Run attempts start at 1; got [{$attempt}].");
        }

        if (isset($this->filters['filter'])) {
            throw new InvalidArgumentException('Ask for every attempt or for one attempt, not both.');
        }

        $this->filters['attempt'] = $attempt;

        return $this;
    }
}
