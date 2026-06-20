<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use RoundlyConsulting\Git\Enums\ProviderName;

final readonly class WebhookEvent extends Dto
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public ProviderName $provider,
        public string $type,
        public array $payload,
    ) {}

    public function isPush(): bool
    {
        return in_array($this->type, ['push', 'repo:push', 'Push Hook'], true);
    }

    public function isPullRequest(): bool
    {
        return in_array($this->type, [
            'pull_request',
            'pullrequest:created',
            'pullrequest:updated',
            'Merge Request Hook',
        ], true);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }
}
