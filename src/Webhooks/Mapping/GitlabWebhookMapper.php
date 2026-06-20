<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Webhooks\Mapping;

use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Mapping\GitlabMapper;

final class GitlabWebhookMapper implements WebhookPayloadMapper
{
    public function __construct(private readonly GitlabMapper $resources) {}

    public function provider(): ProviderName
    {
        return ProviderName::Gitlab;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<Commit>
     */
    public function commits(array $payload): array
    {
        $commits = is_array($payload['commits'] ?? null) ? $payload['commits'] : [];

        return array_values(array_map(
            fn (array $commit): Commit => $this->resources->commit($this->normalizeCommit($commit)),
            $commits,
        ));
    }

    /** @param array<string, mixed> $payload */
    public function pullRequest(array $payload): ?PullRequest
    {
        if (! is_array($payload['object_attributes'] ?? null)) {
            return null;
        }

        return $this->resources->pullRequest($payload['object_attributes']);
    }

    /** @param array<string, mixed> $payload */
    public function repository(array $payload): ?Repository
    {
        $project = $payload['project'] ?? null;

        if (! is_array($project)) {
            return null;
        }

        return $this->resources->repository($this->normalizeProject($project));
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
        $name = $payload['user_name'] ?? null;

        if (! is_string($name)) {
            return null;
        }

        return new Author(
            name: $name,
            email: is_string($payload['user_email'] ?? null) ? $payload['user_email'] : '',
            avatar: is_string($payload['user_avatar'] ?? null) ? $payload['user_avatar'] : null,
            raw: $payload,
        );
    }

    /**
     * Webhook push commits use `timestamp`/`url`; align them with the REST
     * commit shape the resource mapper expects.
     *
     * @param  array<string, mixed>  $commit
     * @return array<string, mixed>
     */
    private function normalizeCommit(array $commit): array
    {
        $commit['authored_date'] ??= $commit['timestamp'] ?? null;
        $commit['author_name'] ??= $commit['author']['name'] ?? '';
        $commit['author_email'] ??= $commit['author']['email'] ?? '';
        $commit['web_url'] ??= $commit['url'] ?? null;

        return $commit;
    }

    /**
     * GitLab webhook projects carry `path_with_namespace` but not always the
     * REST fields the resource mapper reads.
     *
     * @param  array<string, mixed>  $project
     * @return array<string, mixed>
     */
    private function normalizeProject(array $project): array
    {
        $project['path'] ??= str((string) ($project['path_with_namespace'] ?? ''))->afterLast('/')->toString();
        $project['default_branch'] ??= '';
        $project['created_at'] ??= 'now';
        $project['namespace'] ??= [
            'id' => $project['namespace_id'] ?? 0,
            'path' => str((string) ($project['path_with_namespace'] ?? ''))->beforeLast('/')->toString(),
        ];

        return $project;
    }
}
