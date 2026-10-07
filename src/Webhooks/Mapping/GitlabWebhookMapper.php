<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Webhooks\Mapping;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
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

        return $this->resources->pullRequest($this->normalizeMergeRequest(
            $payload['object_attributes'],
            is_array($payload['user'] ?? null) ? $payload['user'] : null,
        ));
    }

    /** @param array<string, mixed> $payload */
    public function repository(array $payload): ?Repository
    {
        $project = $payload['project'] ?? null;

        if (! is_array($project)) {
            return null;
        }

        return $this->resources->repository($this->normalizeProject($project, $payload));
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
     * A merge request hook's `object_attributes` in the REST shape the resource mapper reads.
     *
     * The hook carries `url` where REST has `web_url`, and only an `author_id` where REST
     * has an `author` object. The top-level `user` is whoever TRIGGERED the event, so it
     * stands in for the author only when it is the author; otherwise the author is unknown
     * (null) rather than wrongly the reviewer who merged.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $user
     * @return array<string, mixed>
     */
    private function normalizeMergeRequest(array $attributes, ?array $user): array
    {
        $attributes['web_url'] ??= $attributes['url'] ?? null;

        if (! isset($attributes['author'])
            && $user !== null
            && isset($user['id'], $attributes['author_id'])
            && $user['id'] === $attributes['author_id']) {
            $attributes['author'] = $user;
        }

        return $attributes;
    }

    /**
     * GitLab webhook projects carry `path_with_namespace` but not always the
     * REST fields the resource mapper reads.
     *
     * A real hook's `namespace` is the namespace's display NAME (a string), where REST has
     * an object; it is rebuilt as that object, keeping the name. Its id is `''` unless the
     * hook carries a `namespace_id` — an id is never made up.
     *
     * Hooks carry no project `created_at` or `last_activity_at`: `createdAt` is the time of
     * mapping, `lastActivityAt` the hook's own event time (or the time of mapping when the
     * hook carries none).
     *
     * @param  array<string, mixed>  $project
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeProject(array $project, array $payload): array
    {
        $project['path'] ??= str((string) ($project['path_with_namespace'] ?? ''))->afterLast('/')->toString();
        $project['default_branch'] ??= '';
        $project['created_at'] ??= 'now';

        if (! isset($project['last_activity_at']) && ($eventTime = $this->eventTime($payload)) !== null) {
            $project['last_activity_at'] = $eventTime;
        }

        if (! is_array($project['namespace'] ?? null)) {
            $name = $project['namespace'] ?? null;

            $project['namespace'] = [
                'id' => $project['namespace_id'] ?? '',
                'path' => str((string) ($project['path_with_namespace'] ?? ''))->beforeLast('/')->toString(),
                ...(is_string($name) ? ['name' => $name] : []),
            ];
        }

        return $project;
    }

    /**
     * When the hook's event happened, read from the fields GitLab's webhook docs give each
     * hook kind; the newest when there are several. `null` when the hook carries none.
     *
     * A push dates from its newest commit; a release only on `create`, as an updated
     * release's `created_at` is not the event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function eventTime(array $payload): ?string
    {
        $attributes = is_array($payload['object_attributes'] ?? null) ? $payload['object_attributes'] : [];

        $candidates = match ($payload['object_kind'] ?? null) {
            'push', 'tag_push' => array_column(is_array($payload['commits'] ?? null) ? $payload['commits'] : [], 'timestamp'),
            'pipeline' => [$attributes['finished_at'] ?? null, $attributes['created_at'] ?? null],
            'build' => [$payload['build_finished_at'] ?? null, $payload['build_started_at'] ?? null, $payload['build_created_at'] ?? null],
            'deployment' => [$payload['status_changed_at'] ?? null],
            'release' => ($payload['action'] ?? null) === 'create' ? [$payload['created_at'] ?? null] : [],
            default => [$attributes['updated_at'] ?? null],
        };

        $newest = null;
        $newestAt = null;

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            try {
                $at = Carbon::parse($candidate);
            } catch (InvalidFormatException) {
                continue;
            }

            if ($newestAt === null || $at->greaterThan($newestAt)) {
                [$newest, $newestAt] = [$candidate, $at];
            }
        }

        return $newest;
    }
}
