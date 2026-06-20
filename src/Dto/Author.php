<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

final readonly class Author extends Dto
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $avatar,
    ) {}
}
