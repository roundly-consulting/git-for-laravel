<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Carbon\CarbonInterface;
use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ResourceState;

final readonly class Issue extends Dto
{
    use HasRawPayload;

    /** @param array<string, mixed> $raw */
    public function __construct(
        public ProviderName $provider,
        public string $id,
        public int $number,
        public string $title,
        public ?string $body,
        public ResourceState $state,
        public ?Author $author,
        public ?string $url,
        public CarbonInterface $createdAt,
        public array $raw = [],
    ) {}
}
