<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

final readonly class Tag extends Dto
{
    public function __construct(
        public string $name,
        public ?string $sha,
        public ?string $url,
    ) {}
}
