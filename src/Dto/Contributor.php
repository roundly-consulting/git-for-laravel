<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use RoundlyConsulting\Git\Concerns\HasRawPayload;

final readonly class Contributor extends Dto
{
    use HasRawPayload;

    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $id,
        public string $name,
        public ?string $avatar,
        public int $contributions,
        public array $raw = [],
    ) {}
}
