<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

final readonly class FileContent extends Dto
{
    public function __construct(
        public string $path,
        public string $content,
        public ?string $sha,
        public int $size,
        public ?string $url,
    ) {}
}
