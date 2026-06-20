<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Testing;

final readonly class RecordedCall
{
    /** @param list<mixed> $arguments */
    public function __construct(
        public string $method,
        public array $arguments,
    ) {}
}
