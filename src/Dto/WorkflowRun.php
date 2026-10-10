<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Carbon\CarbonInterface;
use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\WorkflowConclusion;
use RoundlyConsulting\Git\Enums\WorkflowStatus;

/**
 * One GitHub Actions workflow run.
 *
 * `status` is {@see WorkflowStatus::Unknown} for a value GitHub adds later, which is never
 * completed — so {@see isActive()} stays true and a poll keeps waiting rather than reading
 * an unknown state as done. `conclusion` is null until the run completes.
 */
final readonly class WorkflowRun extends Dto
{
    use HasRawPayload;

    /**
     * @param  CarbonInterface|null  $runStartedAt  when the current attempt started. On a queued or
     *                                              waiting run GitHub reports the CREATION time
     *                                              here, so trust it only while the run is
     *                                              `in_progress` (or after it).
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ProviderName $provider,
        public string $id,
        public string $workflowId,
        public string $displayTitle,
        public WorkflowStatus $status,
        public string $event,
        public string $path,
        public string $headSha,
        public CarbonInterface $createdAt,
        public CarbonInterface $updatedAt,
        public ?WorkflowConclusion $conclusion = null,
        public ?string $name = null,
        public ?string $headBranch = null,
        public ?string $headRepository = null,
        public int $runNumber = 1,
        public int $runAttempt = 1,
        public ?Owner $actor = null,
        public ?Owner $triggeringActor = null,
        public ?string $url = null,
        public ?CarbonInterface $runStartedAt = null,
        public array $raw = [],
    ) {}

    public function isCompleted(): bool
    {
        return $this->status === WorkflowStatus::Completed;
    }

    /** Not completed — an Unknown status included, so a poll never stops on a state it cannot read. */
    public function isActive(): bool
    {
        return ! $this->isCompleted();
    }

    public function succeeded(): bool
    {
        return $this->isCompleted() && $this->conclusion === WorkflowConclusion::Success;
    }

    public function wasCancelled(): bool
    {
        return $this->isCompleted() && $this->conclusion === WorkflowConclusion::Cancelled;
    }
}
