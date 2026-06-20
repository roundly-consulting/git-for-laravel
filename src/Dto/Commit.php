<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\ProviderName;

final readonly class Commit extends Dto
{
    use HasRawPayload;

    /** @param array<string, mixed> $raw */
    public function __construct(
        public ProviderName $provider,
        public string $sha,
        public string $message,
        public Author $author,
        public ?string $url,
        public Carbon $commitAt,
        public array $raw = [],
    ) {}
}
