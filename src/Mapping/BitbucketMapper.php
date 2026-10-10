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

final class BitbucketMapper implements ResourceMapper
{
    public function provider(): ProviderName
    {
        return ProviderName::Bitbucket;
    }

    /** @param array<string, mixed> $raw */
    public function repository(array $raw): Repository
    {
        return new Repository(
            provider: $this->provider(),
            id: (string) $raw['uuid'],
            path: $raw['full_name'],
            name: str($raw['full_name'])->after('/')->toString(),
            description: $raw['description'] ?? null,
            defaultBranch: $raw['mainbranch']['name'] ?? '',
            owner: new Owner(
                id: (string) ($raw['owner']['uuid'] ?? ''),
                name: $raw['owner']['username'] ?? ($raw['owner']['display_name'] ?? ''),
                avatar: $raw['owner']['links']['avatar']['href'] ?? null,
                raw: is_array($raw['owner'] ?? null) ? $raw['owner'] : [],
            ),
            createdAt: $createdAt = Carbon::parse($raw['created_on']),
            lastActivityAt: ($raw['updated_on'] ?? null) ? Carbon::parse($raw['updated_on']) : $createdAt,
            raw: $raw,
            private: is_bool($raw['is_private'] ?? null) ? $raw['is_private'] : null,
            language: $this->string($raw['language'] ?? null),
            webUrl: $this->string($raw['links']['html']['href'] ?? null),
        );
    }

    /** @param array<string, mixed> $raw */
    public function commit(array $raw): Commit
    {
        return new Commit(
            provider: $this->provider(),
            sha: $raw['hash'],
            message: $raw['message'],
            author: $this->commitAuthor($raw),
            url: $raw['links']['html']['href'] ?? null,
            commitAt: Carbon::parse($raw['date']),
            raw: $raw,
        );
    }

    /** @param array<string, mixed> $raw */
    public function pullRequest(array $raw): PullRequest
    {
        return new PullRequest(
            provider: $this->provider(),
            id: (string) $raw['id'],
            number: (int) $raw['id'],
            title: $raw['title'],
            body: $raw['description'] ?? null,
            state: ResourceState::fromProvider($this->provider(), (string) ($raw['state'] ?? '')),
            sourceBranch: $raw['source']['branch']['name'] ?? '',
            targetBranch: $raw['destination']['branch']['name'] ?? '',
            author: isset($raw['author']) ? new Author(
                name: $raw['author']['display_name'] ?? '',
                email: '',
                avatar: $raw['author']['links']['avatar']['href'] ?? null,
                raw: $raw['author'],
            ) : null,
            url: $raw['links']['html']['href'] ?? null,
            createdAt: Carbon::parse($raw['created_on']),
            draft: (bool) ($raw['draft'] ?? false),
            raw: $raw,
            // Bitbucket names the head commit by its 12-character short hash.
            headSha: $this->string($raw['source']['commit']['hash'] ?? null),
            headRepository: $this->string($raw['source']['repository']['full_name'] ?? null),
        );
    }

    /** @param array<string, mixed> $raw */
    public function issue(array $raw): Issue
    {
        return new Issue(
            provider: $this->provider(),
            id: (string) $raw['id'],
            number: (int) $raw['id'],
            title: $raw['title'],
            body: $raw['content']['raw'] ?? null,
            state: ResourceState::fromProvider($this->provider(), (string) ($raw['state'] ?? '')),
            author: isset($raw['reporter']) ? new Author(
                name: $raw['reporter']['display_name'] ?? '',
                email: '',
                avatar: $raw['reporter']['links']['avatar']['href'] ?? null,
                raw: $raw['reporter'],
            ) : null,
            url: $raw['links']['html']['href'] ?? null,
            createdAt: Carbon::parse($raw['created_on']),
            raw: $raw,
        );
    }

    /** @param array<string, mixed> $raw */
    public function release(array $raw): Release
    {
        return new Release(
            provider: $this->provider(),
            id: (string) ($raw['name'] ?? ''),
            tagName: $raw['name'] ?? '',
            name: $raw['name'] ?? null,
            body: $raw['message'] ?? null,
            draft: false,
            prerelease: false,
            url: $raw['links']['html']['href'] ?? null,
            createdAt: isset($raw['date']) ? Carbon::parse($raw['date']) : null,
            raw: $raw,
        );
    }

    /** @param array<string, mixed> $raw */
    public function tag(array $raw): Tag
    {
        return new Tag(
            provider: $this->provider(),
            name: $raw['name'],
            sha: $raw['target']['hash'] ?? null,
            url: $raw['links']['html']['href'] ?? null,
            raw: $raw,
        );
    }

    /** A non-empty string, or null — Bitbucket sends `''` for an unset language. */
    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, mixed> $raw */
    private function commitAuthor(array $raw): Author
    {
        $author = is_array($raw['author'] ?? null) ? $raw['author'] : [];

        // REST commits nest a `user` object; webhook push commits carry only the
        // `raw` "Name <email>" string, so tolerate both shapes.
        $name = $author['user']['display_name'] ?? null;

        if (! is_string($name) && isset($author['raw'])) {
            $name = str((string) $author['raw'])->before('<')->trim()->toString();
        }

        $email = isset($author['raw'])
            ? str((string) $author['raw'])->between('<', '>')->toString()
            : '';

        return new Author(
            name: is_string($name) ? $name : '',
            email: $email,
            avatar: $author['user']['links']['avatar']['href'] ?? null,
            raw: $author,
        );
    }
}
