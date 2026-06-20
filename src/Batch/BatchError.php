<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Batch;

use RoundlyConsulting\Git\Dto\Dto;

final readonly class BatchError extends Dto
{
    public function __construct(
        public string $key,
        public ?int $status,
        public string $message,
    ) {}
}
