<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Query;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;
use RoundlyConsulting\Git\Dto\WorkflowRun;
use RoundlyConsulting\Git\Enums\WorkflowConclusion;
use RoundlyConsulting\Git\Enums\WorkflowStatus;

/**
 * GitHub Actions runs — of one workflow or of the whole repository — newest first.
 *
 * The page's `total` is GitHub's `total_count`. A filtered search stops at 1,000 results on
 * GitHub's side, so `lazy()` ends there too: `total` above what was read says so.
 *
 * @extends Query<WorkflowRun>
 */
final class WorkflowRunQuery extends Query
{
    public function branch(string $branch): self
    {
        $this->filters['branch'] = $branch;

        return $this;
    }

    /** The event that triggered the run: `push`, `workflow_dispatch`, `pull_request`, … */
    public function event(string $event): self
    {
        $this->filters['event'] = $event;

        return $this;
    }

    /**
     * A status (`in_progress`) or a conclusion (`failure`) — GitHub takes both here.
     *
     * @throws InvalidArgumentException for `Unknown`, the package's placeholder for a value
     *                                  GitHub never sends
     */
    public function status(WorkflowStatus|WorkflowConclusion $status): self
    {
        if ($status === WorkflowStatus::Unknown || $status === WorkflowConclusion::Unknown) {
            throw new InvalidArgumentException('Runs cannot be filtered by [unknown]: it is the package\'s placeholder for a value GitHub did not document.');
        }

        $this->filters['status'] = $status->value;

        return $this;
    }

    /** The login of the account that started the run. */
    public function actor(string $login): self
    {
        $this->filters['actor'] = $login;

        return $this;
    }

    public function headSha(string $sha): self
    {
        $this->filters['headSha'] = $sha;

        return $this;
    }

    /** Created at or after this moment (to the second, UTC). */
    public function createdAfter(CarbonInterface $at): self
    {
        $this->filters['createdAfter'] = self::utc($at);

        return $this;
    }

    /** Created at or before this moment (to the second, UTC). */
    public function createdBefore(CarbonInterface $at): self
    {
        $this->filters['createdBefore'] = self::utc($at);

        return $this;
    }

    /** Leave each run's `pull_requests` list out of the answer — it shapes the payload, it filters nothing. */
    public function excludePullRequests(): self
    {
        $this->filters['excludePullRequests'] = true;

        return $this;
    }

    /** GitHub's `created` search value: to the second, in UTC, never moving the caller's instance. */
    private static function utc(CarbonInterface $at): string
    {
        return CarbonImmutable::instance($at)->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
