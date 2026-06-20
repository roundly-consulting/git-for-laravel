<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

final readonly class RateLimit extends Dto
{
    public function __construct(
        public string $key,
        public int $maxAttempts,
        public string $timespan,
    ) {}

    public function decaySeconds(): int
    {
        return match ($this->timespan) {
            'second' => 1,
            'minute' => 60,
            'hour' => 3600,
            'day' => 86400,
            default => (int) $this->timespan,
        };
    }
}
