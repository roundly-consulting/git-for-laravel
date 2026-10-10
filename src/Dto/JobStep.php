<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Carbon\CarbonInterface;
use RoundlyConsulting\Git\Enums\WorkflowConclusion;
use RoundlyConsulting\Git\Enums\WorkflowStatus;

/** One step of a GitHub Actions job. Its raw payload is part of the job's `raw()`. */
final readonly class JobStep extends Dto
{
    public function __construct(
        public int $number,
        public string $name,
        public WorkflowStatus $status,
        public ?WorkflowConclusion $conclusion = null,
        public ?CarbonInterface $startedAt = null,
        public ?CarbonInterface $completedAt = null,
    ) {}

    public function succeeded(): bool
    {
        return $this->status === WorkflowStatus::Completed && $this->conclusion === WorkflowConclusion::Success;
    }
}
