<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Carbon\CarbonInterface;
use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\ProviderName;

final readonly class Release extends Dto
{
    use HasRawPayload;

    /** @param array<string, mixed> $raw */
    public function __construct(
        public ProviderName $provider,
        public string $id,
        public string $tagName,
        public ?string $name,
        public ?string $body,
        public bool $draft,
        public bool $prerelease,
        public ?string $url,
        public ?CarbonInterface $createdAt,
        public array $raw = [],
    ) {}
}
