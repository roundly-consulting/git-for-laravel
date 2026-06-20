<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

final readonly class ComparisonFile extends Dto
{
    public function __construct(
        public string $filename,
        public string $status,
        public int $additions,
        public int $deletions,
    ) {}
}
