<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Carbon\CarbonInterface;
use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\ActivityType;
use RoundlyConsulting\Git\Enums\ProviderName;

/**
 * One entry of a repository's activity feed — a push, force push, branch creation or
 * deletion, or a merge, on one ref.
 *
 * `before` is `null` when the ref did not exist before (a creation), `after` when it no
 * longer exists (a deletion): GitHub sends an all-zero sha there, which names no commit.
 * `actor` is `null` when GitHub names nobody (a deleted account).
 */
final readonly class Activity extends Dto
{
    use HasRawPayload;

    /** @param array<string, mixed> $raw */
    public function __construct(
        public ProviderName $provider,
        public string $id,
        public ActivityType $type,
        public string $ref,
        public ?string $before,
        public ?string $after,
        public ?Owner $actor,
        public CarbonInterface $occurredAt,
        public array $raw = [],
    ) {}
}
