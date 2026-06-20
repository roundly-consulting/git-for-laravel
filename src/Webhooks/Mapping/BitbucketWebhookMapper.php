<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Webhooks\Mapping;

use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Mapping\BitbucketMapper;

final class BitbucketWebhookMapper implements WebhookPayloadMapper
{
    public function __construct(private readonly BitbucketMapper $resources) {}

    public function provider(): ProviderName
    {
        return ProviderName::Bitbucket;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Commit>
     */
    public function commits(array $payload): array
    {
        $commits = [];

        foreach ($this->changes($payload) as $change) {
            foreach (is_array($change['commits'] ?? null) ? $change['commits'] : [] as $commit) {
                $commits[] = $this->resources->commit($commit);
            }
        }

        return $commits;
    }

    /** @param array<string, mixed> $payload */
    public function pullRequest(array $payload): ?PullRequest
    {
        if (! is_array($payload['pullrequest'] ?? null)) {
            return null;
        }

        return $this->resources->pullRequest($payload['pullrequest']);
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
        foreach ($this->changes($payload) as $change) {
            $name = $change['new']['name'] ?? null;

            if (is_string($name)) {
                return $name;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $payload */
    public function pusher(array $payload): ?Author
    {
        $actor = $payload['actor'] ?? null;

        if (! is_array($actor)) {
            return null;
        }

        return new Author(
            name: $actor['display_name'] ?? '',
            email: '',
            avatar: $actor['links']['avatar']['href'] ?? null,
            raw: $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function changes(array $payload): array
    {
        $changes = $payload['push']['changes'] ?? null;

        if (! is_array($changes)) {
            return [];
        }

        /** @var list<array<string, mixed>> $list */
        $list = array_values(array_filter($changes, 'is_array'));

        return $list;
    }
}
