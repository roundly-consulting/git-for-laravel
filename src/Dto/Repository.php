<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\ProviderName;

final readonly class Repository extends Dto
{
    use HasRawPayload;

    /** @param array<string, mixed> $raw */
    public function __construct(
        public ProviderName $provider,
        public string $id,
        public string $path,
        public string $name,
        public ?string $description,
        public string $defaultBranch,
        public Owner $owner,
        public Carbon $createdAt,
        public Carbon $lastActivityAt,
        public array $raw = [],
    ) {}
}
