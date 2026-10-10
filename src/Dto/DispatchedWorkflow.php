<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Carbon\CarbonInterface;
use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\ProviderName;

/**
 * A workflow dispatch GitHub accepted.
 *
 * `runId` (with `apiUrl` and `url`) is the run GitHub started, when it said so — github.com
 * and GHE.com do. GitHub Enterprise Server answers without it, so they are null there: find
 * the run with a `runs()` query instead (see the docs' "finding a dispatched run" recipe),
 * starting from `dispatchedAt`, the local clock read BEFORE the request was sent.
 */
final readonly class DispatchedWorkflow extends Dto
{
    use HasRawPayload;

    /** @param array<string, mixed> $raw */
    public function __construct(
        public ProviderName $provider,
        public string $workflow,
        public string $ref,
        public ?string $runId,
        public ?string $apiUrl,
        public ?string $url,
        public CarbonInterface $dispatchedAt,
        public array $raw = [],
    ) {}
}
