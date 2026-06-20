<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use RoundlyConsulting\Git\Concerns\HasRawPayload;

final readonly class FileContent extends Dto
{
    use HasRawPayload;

    /** @param array<string, mixed> $raw */
    public function __construct(
        public string $path,
        public string $content,
        public ?string $sha,
        public int $size,
        public ?string $url,
        public array $raw = [],
    ) {}
}
