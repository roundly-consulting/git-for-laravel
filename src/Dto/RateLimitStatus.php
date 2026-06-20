<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Carbon\CarbonInterface;

final readonly class RateLimitStatus extends Dto
{
    public function __construct(
        public int $limit,
        public int $remaining,
        public int $used,
        public ?CarbonInterface $resetAt,
    ) {}
}
