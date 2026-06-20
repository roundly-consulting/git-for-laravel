<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use RoundlyConsulting\Git\Concerns\HasRawPayload;

final readonly class Author extends Dto
{
    use HasRawPayload;

    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $name,
        public string $email,
        public ?string $avatar,
        public array $raw = [],
    ) {}
}
