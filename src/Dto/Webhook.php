<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

final readonly class Webhook extends Dto
{
    /** @param list<string> $events */
    public function __construct(
        public string $id,
        public string $url,
        public array $events,
        public bool $active,
    ) {}
}
