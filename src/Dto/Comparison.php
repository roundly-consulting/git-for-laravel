<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use RoundlyConsulting\Git\Concerns\HasRawPayload;

final readonly class Comparison extends Dto
{
    use HasRawPayload;

    /**
     * @param  list<ComparisonFile>  $files
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $base,
        public string $head,
        public int $aheadBy,
        public int $behindBy,
        public array $files,
        public array $raw = [],
    ) {}
}
