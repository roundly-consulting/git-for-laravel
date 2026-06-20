<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use RoundlyConsulting\Git\Enums\Timespan;

final readonly class RateLimit extends Dto
{
    public function __construct(
        public string $key,
        public int $maxAttempts,
        public Timespan|int $timespan,
    ) {}

    public function decaySeconds(): int
    {
        if ($this->timespan instanceof Timespan) {
            return $this->timespan->seconds();
        }

        return $this->timespan;
    }
}
