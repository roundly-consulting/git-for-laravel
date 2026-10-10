<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Carbon\CarbonInterface;
use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\WorkflowConclusion;
use RoundlyConsulting\Git\Enums\WorkflowStatus;

/**
 * One job of a GitHub Actions run, with its steps.
 *
 * `runAttempt` says which attempt of the run it belongs to: a re-run starts a new attempt
 * with new jobs, and the jobs query reads the latest attempt unless told otherwise.
 */
final readonly class WorkflowJob extends Dto
{
    use HasRawPayload;

    /**
     * @param  list<string>  $labels
     * @param  list<JobStep>  $steps
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ProviderName $provider,
        public string $id,
        public string $runId,
        public string $name,
        public WorkflowStatus $status,
        public string $headSha,
        public ?WorkflowConclusion $conclusion = null,
        public int $runAttempt = 1,
        public ?string $workflowName = null,
        public ?string $headBranch = null,
        public ?CarbonInterface $startedAt = null,
        public ?CarbonInterface $completedAt = null,
        public ?string $runnerName = null,
        public array $labels = [],
        public ?string $url = null,
        public array $steps = [],
        public array $raw = [],
    ) {}

    public function succeeded(): bool
    {
        return $this->status === WorkflowStatus::Completed && $this->conclusion === WorkflowConclusion::Success;
    }

    /** The first step with exactly this name, or null. */
    public function step(string $name): ?JobStep
    {
        foreach ($this->steps as $step) {
            if ($step->name === $name) {
                return $step;
            }
        }

        return null;
    }
}
