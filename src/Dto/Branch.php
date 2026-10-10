<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\ProviderName;

/**
 * One branch and the commit at its head, found by its EXACT name.
 *
 * Unlike `commit($ref)`, which resolves any commit-ish (a tag, a short sha), this is only
 * ever a branch: a tag, a sha or a mere prefix of a branch name is a `404`.
 */
final readonly class Branch extends Dto
{
    use HasRawPayload;

    /** @param array<string, mixed> $raw */
    public function __construct(
        public ProviderName $provider,
        public string $name,
        public string $sha,
        public array $raw = [],
    ) {}
}
