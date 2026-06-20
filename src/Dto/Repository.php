<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Illuminate\Support\Carbon;

final readonly class Repository extends Dto
{
    public function __construct(
        public string $id,
        public string $path,
        public string $name,
        public ?string $description,
        public string $defaultBranch,
        public Owner $owner,
        public Carbon $createdAt,
        public Carbon $lastActivityAt,
    ) {}
}
