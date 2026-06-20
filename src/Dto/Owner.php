<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

final readonly class Owner extends Dto
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $avatar,
    ) {}
}
