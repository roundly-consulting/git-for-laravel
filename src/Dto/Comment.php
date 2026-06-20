<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Carbon\CarbonInterface;

final readonly class Comment extends Dto
{
    public function __construct(
        public string $id,
        public string $body,
        public ?Author $author,
        public ?string $url,
        public CarbonInterface $createdAt,
    ) {}
}
