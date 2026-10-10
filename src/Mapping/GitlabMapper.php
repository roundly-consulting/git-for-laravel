<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Mapping;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Issue;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ResourceState;

final class GitlabMapper implements ResourceMapper
{
    public function provider(): ProviderName
    {
        return ProviderName::Gitlab;
    }

    /** @param array<string, mixed> $raw */
    public function repository(array $raw): Repository
    {
        return new Repository(
            provider: $this->provider(),
            id: (string) $raw['id'],
            path: $raw['path_with_namespace'],
            name: $raw['path'],
            description: $raw['description'] ?? null,
            defaultBranch: $raw['default_branch'],
            owner: new Owner(
                id: (string) $raw['namespace']['id'],
                name: $raw['namespace']['path'],
                avatar: $raw['namespace']['avatar_url'] ?? null,
                raw: $raw['namespace'],
            ),
            createdAt: $createdAt = Carbon::parse($raw['created_at']),
            lastActivityAt: ($raw['last_activity_at'] ?? null) ? Carbon::parse($raw['last_activity_at']) : $createdAt,
            raw: $raw,
            // `internal` (any signed-in user) counts as private, as GitHub counts it.
            private: is_string($raw['visibility'] ?? null) ? $raw['visibility'] !== 'public' : null,
            language: null,
            webUrl: is_string($raw['web_url'] ?? null) && $raw['web_url'] !== '' ? $raw['web_url'] : null,
        );
    }

    /** @param array<string, mixed> $raw */
    public function commit(array $raw): Commit
    {
        return new Commit(
            provider: $this->provider(),
            sha: $raw['id'],
            message: $raw['message'],
            author: new Author(
                name: $raw['author_name'],
                email: $raw['author_email'],
                avatar: null,
            ),
            url: $raw['web_url'] ?? $raw['url'] ?? null,
            commitAt: Carbon::parse($raw['authored_date'] ?? $raw['timestamp'] ?? 'now'),
            raw: $raw,
        );
    }

    /** @param array<string, mixed> $raw */
    public function pullRequest(array $raw): PullRequest
    {
        return new PullRequest(
            provider: $this->provider(),
            id: (string) $raw['id'],
            number: (int) $raw['iid'],
            title: $raw['title'],
            body: $raw['description'] ?? null,
            state: ResourceState::fromProvider($this->provider(), (string) ($raw['state'] ?? '')),
            sourceBranch: $raw['source_branch'] ?? '',
            targetBranch: $raw['target_branch'] ?? '',
            author: isset($raw['author']) ? new Author(
                name: $raw['author']['username'],
                email: '',
                avatar: $raw['author']['avatar_url'] ?? null,
                raw: $raw['author'],
            ) : null,
            url: $raw['web_url'] ?? null,
            createdAt: Carbon::parse($raw['created_at']),
            draft: (bool) ($raw['draft'] ?? false),
            raw: $raw,
            // REST carries `sha`; a merge request hook only `last_commit.id`.
            headSha: $this->headSha($raw),
            headRepository: null,
        );
    }

    /** @param array<string, mixed> $raw */
    private function headSha(array $raw): ?string
    {
        $sha = $raw['sha'] ?? (is_array($raw['last_commit'] ?? null) ? ($raw['last_commit']['id'] ?? null) : null);

        return is_string($sha) && $sha !== '' ? $sha : null;
    }

    /** @param array<string, mixed> $raw */
    public function issue(array $raw): Issue
    {
        return new Issue(
            provider: $this->provider(),
            id: (string) $raw['id'],
            number: (int) $raw['iid'],
            title: $raw['title'],
            body: $raw['description'] ?? null,
            state: ResourceState::fromProvider($this->provider(), (string) ($raw['state'] ?? '')),
            author: isset($raw['author']) ? new Author(
                name: $raw['author']['username'],
                email: '',
                avatar: $raw['author']['avatar_url'] ?? null,
                raw: $raw['author'],
            ) : null,
            url: $raw['web_url'] ?? null,
            createdAt: Carbon::parse($raw['created_at']),
            raw: $raw,
        );
    }

    /** @param array<string, mixed> $raw */
    public function release(array $raw): Release
    {
        $self = $raw['_links']['self'] ?? null;

        return new Release(
            provider: $this->provider(),
            id: (string) ($raw['tag_name'] ?? ''),
            tagName: $raw['tag_name'],
            name: $raw['name'] ?? null,
            body: $raw['description'] ?? null,
            draft: (bool) ($raw['upcoming_release'] ?? false),
            prerelease: false,
            url: is_string($self) ? $self : null,
            createdAt: isset($raw['created_at']) ? Carbon::parse($raw['created_at']) : null,
            raw: $raw,
        );
    }

    /** @param array<string, mixed> $raw */
    public function tag(array $raw): Tag
    {
        return new Tag(
            provider: $this->provider(),
            name: $raw['name'],
            sha: $raw['commit']['id'] ?? null,
            url: null,
            raw: $raw,
        );
    }
}
