<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use RoundlyConsulting\Git\Concerns\HasRawPayload;

final readonly class ComparisonFile extends Dto
{
    use HasRawPayload;

    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $filename,
        public string $status,
        public int $additions,
        public int $deletions,
        public array $raw = [],
    ) {}
}
