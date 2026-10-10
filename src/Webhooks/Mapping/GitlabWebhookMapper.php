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

    /**
     * The merge request the hook is about; `null` when it carries none.
     *
     * A Merge Request Hook carries it as `object_attributes`; a Note Hook on a merge request
     * and a merge request pipeline's Pipeline Hook as a top-level `merge_request`. Issue,
     * comment and pipeline hooks carry `object_attributes` too — the issue, the note, the
     * pipeline — so the hook's own `object_kind` decides where to look.
     *
     * @param  array<string, mixed>  $payload
     */
    public function pullRequest(array $payload): ?PullRequest
    {
        $mergeRequest = $this->mergeRequestOf($payload);

        if ($mergeRequest === null) {
            return null;
        }

        return $this->resources->pullRequest($this->normalizeMergeRequest(
            $mergeRequest,
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private function mergeRequestOf(array $payload): ?array
    {
        $attributes = $payload['object_attributes'] ?? null;
        $nested = is_array($payload['merge_request'] ?? null) ? $payload['merge_request'] : null;

        return match ($payload['object_kind'] ?? null) {
            'merge_request' => is_array($attributes) ? $attributes : null,
            'note' => is_array($attributes) && ($attributes['noteable_type'] ?? null) === 'MergeRequest' ? $nested : null,
            'pipeline' => $nested,
            default => null,
        };
    }

    /**
     * A hook's merge request in the REST shape the resource mapper reads.
     *
     * Hooks carry `url` where REST has `web_url` (a Note Hook's `merge_request` has neither,
     * so its url is null), and only an `author_id` where REST has an `author` object. The
     * top-level `user` is whoever TRIGGERED the event, so it stands in for the author only
     * when it is the author; otherwise the author is unknown (null) rather than wrongly the
     * reviewer who merged or the commenter.
     *
     * A merge request pipeline's slim `merge_request` carries no `created_at`: `createdAt` is
     * then the time of mapping, as for a hook repository — fetch the pull request through the
     * API for the real one.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $user
     * @return array<string, mixed>
     */
    private function normalizeMergeRequest(array $attributes, ?array $user): array
    {
        $attributes['web_url'] ??= $attributes['url'] ?? null;
        $attributes['created_at'] ??= 'now';

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

        // Hooks carry `visibility_level` (0 private, 10 internal, 20 public), REST `visibility`.
        if (! isset($project['visibility']) && is_int($project['visibility_level'] ?? null)) {
            $project['visibility'] = match ($project['visibility_level']) {
                20 => 'public',
                10 => 'internal',
                default => 'private',
            };
        }

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
