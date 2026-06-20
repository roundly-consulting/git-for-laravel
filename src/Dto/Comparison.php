<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

final readonly class Comparison extends Dto
{
    /** @param list<ComparisonFile> $files */
    public function __construct(
        public string $base,
        public string $head,
        public int $aheadBy,
        public int $behindBy,
        public array $files,
    ) {}
}
