<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\ProviderName;

final readonly class Webhook extends Dto
{
    use HasRawPayload;

    /**
     * @param  list<string>  $events
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ProviderName $provider,
        public string $id,
        public string $url,
        public array $events,
        public bool $active,
        public array $raw = [],
    ) {}
}
