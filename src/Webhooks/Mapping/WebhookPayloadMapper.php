<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Webhooks\Mapping;

use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Enums\ProviderName;

/**
 * Knows where each provider nests its resources inside a webhook body, and
 * delegates the canonical resource construction to the matching ResourceMapper.
 */
interface WebhookPayloadMapper
{
    public function provider(): ProviderName;

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Commit>
     */
    public function commits(array $payload): array;

    /** @param array<string, mixed> $payload */
    public function pullRequest(array $payload): ?PullRequest;

    /** @param array<string, mixed> $payload */
    public function repository(array $payload): ?Repository;

    /** @param array<string, mixed> $payload */
    public function ref(array $payload): ?string;

    /** @param array<string, mixed> $payload */
    public function pusher(array $payload): ?Author;
}
