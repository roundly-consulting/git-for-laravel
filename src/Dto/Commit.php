<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Illuminate\Support\Carbon;

final readonly class Commit extends Dto
{
    public function __construct(
        public string $sha,
        public string $message,
        public Author $author,
        public ?string $url,
        public Carbon $commitAt,
    ) {}
}
