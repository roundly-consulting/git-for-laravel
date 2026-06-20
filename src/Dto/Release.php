<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Carbon\CarbonInterface;

final readonly class Release extends Dto
{
    public function __construct(
        public string $id,
        public string $tagName,
        public ?string $name,
        public ?string $body,
        public bool $draft,
        public bool $prerelease,
        public ?string $url,
        public ?CarbonInterface $createdAt,
    ) {}
}
