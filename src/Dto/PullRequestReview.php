<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Carbon\CarbonInterface;
use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ReviewEvent;

/**
 * A review left on a pull request.
 *
 * `state` is the forge's own word (`COMMENTED`, `APPROVED`, `CHANGES_REQUESTED`,
 * `DISMISSED`, …) rather than a {@see ReviewEvent}: the two vocabularies are not the same
 * set — a review can be dismissed, which no caller can ever submit — and narrowing a read
 * to the write's enum would drop states this package has no business hiding.
 */
final readonly class PullRequestReview extends Dto
{
    use HasRawPayload;

    /** @param array<string, mixed> $raw */
    public function __construct(
        public ProviderName $provider,
        public string $id,
        public string $state,
        public ?string $body,
        public ?Author $author,
        public ?string $url,
        /** Null while a review is still a pending draft — it has not been submitted. */
        public ?CarbonInterface $submittedAt,
        public array $raw = [],
    ) {}
}
