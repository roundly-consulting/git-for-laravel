<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Webhooks\Mapping;

use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Mapping\GithubMapper;

final class GithubWebhookMapper implements WebhookPayloadMapper
{
    public function __construct(private readonly GithubMapper $resources) {}

    public function provider(): ProviderName
    {
        return ProviderName::Github;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Commit>
     */
    public function commits(array $payload): array
    {
        $commits = is_array($payload['commits'] ?? null) ? $payload['commits'] : [];

        return array_values(array_map(
            fn (array $commit): Commit => $this->resources->commit($commit),
            $commits,
        ));
    }

    /** @param array<string, mixed> $payload */
    public function pullRequest(array $payload): ?PullRequest
    {
        if (! is_array($payload['pull_request'] ?? null)) {
            return null;
        }

        return $this->resources->pullRequest($payload['pull_request']);
    }

    /** @param array<string, mixed> $payload */
    public function repository(array $payload): ?Repository
    {
        if (! is_array($payload['repository'] ?? null)) {
            return null;
        }

        return $this->resources->repository($payload['repository']);
    }

    /** @param array<string, mixed> $payload */
    public function ref(array $payload): ?string
    {
        $ref = $payload['ref'] ?? null;

        return is_string($ref) ? $ref : null;
    }

    /** @param array<string, mixed> $payload */
    public function pusher(array $payload): ?Author
    {
        if (! is_array($payload['pusher'] ?? null)) {
            return null;
        }

        $pusher = $payload['pusher'];

        return new Author(
            name: $pusher['name'] ?? '',
            email: $pusher['email'] ?? '',
            avatar: null,
            raw: $pusher,
        );
    }
}
