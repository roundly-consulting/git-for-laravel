<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

final readonly class Contributor extends Dto
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $avatar,
        public int $contributions,
    ) {}
}
