<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Mapping;

use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RoundlyConsulting\Git\Dto\Activity;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Dto\Issue;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\PullRequestReview;
use RoundlyConsulting\Git\Dto\PullRequestReviewComment;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Enums\ActivityType;
use RoundlyConsulting\Git\Enums\DiffSide;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ResourceState;
use Throwable;

final class GithubMapper implements ResourceMapper
{
    public function provider(): ProviderName
    {
        return ProviderName::Github;
    }

    /** @param array<string, mixed> $raw */
    public function repository(array $raw): Repository
    {
        return new Repository(
            provider: $this->provider(),
            id: (string) $raw['id'],
            path: $raw['full_name'],
            name: $raw['name'],
            description: $raw['description'] ?? null,
            defaultBranch: $raw['default_branch'],
            owner: new Owner(
                id: (string) $raw['owner']['id'],
                name: $raw['owner']['login'],
                avatar: $raw['owner']['avatar_url'] ?? null,
                raw: $raw['owner'],
            ),
            createdAt: $createdAt = Carbon::parse($raw['created_at']),
            lastActivityAt: ($raw['pushed_at'] ?? null) ? Carbon::parse($raw['pushed_at']) : $createdAt,
            raw: $raw,
        );
    }

    /**
     * A GitHub App installation.
     *
     * GitHub-only, so it is NOT on the shared ResourceMapper contract — GitLab and
     * Bitbucket have no equivalent concept.
     *
     * Every field is coerced rather than asserted: this payload is what the
     * verify-before-you-trust path reads, so a malformed one must fail here rather than
     * flow into a typed DTO an analyzer then believes.
     *
     * @param  array<string, mixed>  $raw
     *
     * @throws InvalidArgumentException when the payload carries no installation id
     */
    public function installation(array $raw): Installation
    {
        $id = $raw['id'] ?? null;

        if (! is_int($id) && ! (is_string($id) && $id !== '')) {
            throw new InvalidArgumentException('A GitHub installation payload carried no usable id.');
        }

        $account = is_array($raw['account'] ?? null) ? $raw['account'] : [];
        $accountType = $account['type'] ?? null;
        $login = $account['login'] ?? null;
        $selection = $raw['repository_selection'] ?? null;
        $suspendedAt = $raw['suspended_at'] ?? null;

        $permissions = [];
        foreach (is_array($raw['permissions'] ?? null) ? $raw['permissions'] : [] as $name => $access) {
            if (is_string($name) && is_string($access)) {
                $permissions[$name] = $access;
            }
        }

        return new Installation(
            provider: $this->provider(),
            id: (string) $id,
            accountLogin: is_string($login) ? $login : '',
            // GitHub says "Organization" or "User".
            accountType: is_string($accountType) ? $accountType : 'Organization',
            // Absent means the payload predates the field, and "selected" is the
            // conservative reading: claiming `all` we were not told about would show a
            // warning nobody can act on.
            repositorySelection: $selection === 'all' ? 'all' : 'selected',
            permissions: $permissions,
            // A malformed timestamp must not throw out of a mapper on the verify path,
            // and it must not read as NOT suspended either — that would turn an unparseable
            // date into "this installation is fine". Unknown-but-present means suspended.
            suspendedAt: $this->suspendedAt($suspendedAt),
            raw: $raw,
        );
    }

    /**
     * One entry of a repository's activity feed.
     *
     * GitHub-only, so it is NOT on the shared ResourceMapper contract. An all-zero
     * `before` / `after` (a branch creation / deletion) names no commit and maps to null.
     *
     * @param  array<string, mixed>  $raw
     *
     * @throws InvalidArgumentException when the payload carries no usable id or timestamp
     */
    public function activity(array $raw): Activity
    {
        $type = $raw['activity_type'] ?? null;
        $ref = $raw['ref'] ?? null;

        return new Activity(
            provider: $this->provider(),
            id: $this->requiredId($raw, 'id', 'activity'),
            type: (is_string($type) ? ActivityType::tryFrom($type) : null) ?? ActivityType::Unknown,
            ref: is_string($ref) ? $ref : '',
            before: $this->commitOrNull($raw['before'] ?? null),
            after: $this->commitOrNull($raw['after'] ?? null),
            actor: $this->owner($raw['actor'] ?? null),
            occurredAt: $this->timestamp($raw['timestamp'] ?? null)
                ?? throw new InvalidArgumentException('A GitHub activity payload carried no usable [timestamp].'),
            raw: $raw,
        );
    }

    /**
     * A forge-issued id as a string, or a refusal naming the field — never a made-up `0`.
     *
     * @param  array<string, mixed>  $raw
     *
     * @throws InvalidArgumentException
     */
    private function requiredId(array $raw, string $field, string $resource): string
    {
        $id = $raw[$field] ?? null;

        if (is_int($id) || (is_string($id) && $id !== '')) {
            return (string) $id;
        }

        throw new InvalidArgumentException("A GitHub {$resource} payload carried no usable [{$field}].");
    }

    /** A sha, or null for an absent one or GitHub's all-zero "no commit". */
    private function commitOrNull(mixed $sha): ?string
    {
        return is_string($sha) && $sha !== '' && trim($sha, '0') !== '' ? $sha : null;
    }

    /** A GitHub user, or null when the payload names nobody. */
    private function owner(mixed $user): ?Owner
    {
        if (! is_array($user) || ! is_string($user['login'] ?? null) || $user['login'] === '') {
            return null;
        }

        $id = $user['id'] ?? null;
        $avatar = $user['avatar_url'] ?? null;

        return new Owner(
            id: is_int($id) || is_string($id) ? (string) $id : '',
            name: $user['login'],
            avatar: is_string($avatar) ? $avatar : null,
            raw: $user,
        );
    }

    private function suspendedAt(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return Carbon::now();
        }
    }

    /** @param array<string, mixed> $raw */
    public function commit(array $raw): Commit
    {
        return new Commit(
            provider: $this->provider(),
            sha: $raw['sha'] ?? $raw['id'],
            message: $raw['commit']['message'] ?? $raw['message'] ?? '',
            author: $this->commitAuthor($raw),
            url: $raw['html_url'] ?? $raw['url'] ?? null,
            commitAt: Carbon::parse($this->commitDate($raw)),
            raw: $raw,
        );
    }

    /** @param array<string, mixed> $raw */
    public function pullRequest(array $raw): PullRequest
    {
        $state = ResourceState::fromProvider($this->provider(), (string) ($raw['state'] ?? ''));

        if (($raw['merged_at'] ?? null) !== null) {
            $state = ResourceState::Merged;
        }

        return new PullRequest(
            provider: $this->provider(),
            id: (string) $raw['id'],
            number: (int) $raw['number'],
            title: $raw['title'],
            body: $raw['body'] ?? null,
            state: $state,
            sourceBranch: $raw['head']['ref'] ?? '',
            targetBranch: $raw['base']['ref'] ?? '',
            author: isset($raw['user']) ? new Author(
                name: $raw['user']['login'],
                email: '',
                avatar: $raw['user']['avatar_url'] ?? null,
                raw: $raw['user'],
            ) : null,
            url: $raw['html_url'] ?? null,
            createdAt: Carbon::parse($raw['created_at']),
            draft: (bool) ($raw['draft'] ?? false),
            raw: $raw,
        );
    }

    /**
     * A review on a pull request.
     *
     * `submitted_at` is absent while a review is a PENDING draft, and null here says so
     * rather than reading as "submitted at the epoch".
     *
     * @param  array<string, mixed>  $raw
     */
    public function pullRequestReview(array $raw): PullRequestReview
    {
        return new PullRequestReview(
            provider: $this->provider(),
            id: (string) $raw['id'],
            state: (string) ($raw['state'] ?? ''),
            body: ($raw['body'] ?? '') === '' ? null : $raw['body'],
            author: $this->reviewAuthor($raw),
            url: $raw['html_url'] ?? null,
            submittedAt: $this->timestamp($raw['submitted_at'] ?? null),
            raw: $raw,
        );
    }

    /**
     * One inline comment on a pull request's diff.
     *
     * `line` is null on an OUTDATED comment — the lines it was anchored to were
     * rewritten, so GitHub keeps the comment and drops the anchor. Falling back to
     * `original_line` would point a reader at whatever occupies that line NOW, which is
     * code the comment never mentioned.
     *
     * @param  array<string, mixed>  $raw
     */
    public function pullRequestReviewComment(array $raw): PullRequestReviewComment
    {
        $line = $raw['line'] ?? null;
        $reviewId = $raw['pull_request_review_id'] ?? null;

        return new PullRequestReviewComment(
            provider: $this->provider(),
            id: (string) $raw['id'],
            body: (string) ($raw['body'] ?? ''),
            path: (string) ($raw['path'] ?? ''),
            line: is_numeric($line) ? (int) $line : null,
            side: DiffSide::tryFrom(mb_strtolower((string) ($raw['side'] ?? ''))),
            author: $this->reviewAuthor($raw),
            url: $raw['html_url'] ?? null,
            createdAt: $this->timestamp($raw['created_at'] ?? null),
            reviewId: is_int($reviewId) || (is_string($reviewId) && $reviewId !== '') ? (string) $reviewId : null,
            raw: $raw,
        );
    }

    /**
     * A timestamp, or null when the payload does not carry a usable one.
     *
     * `Carbon::parse('')` is NOW, so an empty or absent field would date a comment to
     * the moment it was read — which sorts to the top of any thread and reads as the
     * newest thing said. Null is the only honest answer to "when" nobody told us.
     */
    private function timestamp(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }

    /**
     * The author, or null when there is nobody to name.
     *
     * A deleted account comes back with no `login`, and an `Author` whose name is `''` is
     * worse than none: every reader downstream tests the AUTHOR for null, so an empty
     * string reads as "somebody" and prints as nothing.
     *
     * @param  array<string, mixed>  $raw
     */
    private function reviewAuthor(array $raw): ?Author
    {
        if (! isset($raw['user']) || ! is_array($raw['user']) || ($raw['user']['login'] ?? '') === '') {
            return null;
        }

        return new Author(
            name: (string) $raw['user']['login'],
            email: '',
            avatar: $raw['user']['avatar_url'] ?? null,
            raw: $raw['user'],
        );
    }

    /** @param array<string, mixed> $raw */
    public function issue(array $raw): Issue
    {
        return new Issue(
            provider: $this->provider(),
            id: (string) $raw['id'],
            number: (int) $raw['number'],
            title: $raw['title'],
            body: $raw['body'] ?? null,
            state: ResourceState::fromProvider($this->provider(), (string) ($raw['state'] ?? '')),
            author: isset($raw['user']) ? new Author(
                name: $raw['user']['login'],
                email: '',
                avatar: $raw['user']['avatar_url'] ?? null,
                raw: $raw['user'],
            ) : null,
            url: $raw['html_url'] ?? null,
            createdAt: Carbon::parse($raw['created_at']),
            raw: $raw,
        );
    }

    /** @param array<string, mixed> $raw */
    public function release(array $raw): Release
    {
        return new Release(
            provider: $this->provider(),
            id: (string) $raw['id'],
            tagName: $raw['tag_name'],
            name: $raw['name'] ?? null,
            body: $raw['body'] ?? null,
            draft: (bool) ($raw['draft'] ?? false),
            prerelease: (bool) ($raw['prerelease'] ?? false),
            url: $raw['html_url'] ?? null,
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
            sha: $raw['commit']['sha'] ?? null,
            url: $raw['commit']['url'] ?? null,
            raw: $raw,
        );
    }

    /** @param array<string, mixed> $raw */
    private function commitAuthor(array $raw): Author
    {
        // REST commit shape carries author under `commit.author`; webhook push
        // commits carry a flat `author` object instead.
        if (isset($raw['commit']['author'])) {
            return new Author(
                name: $raw['commit']['author']['name'] ?? '',
                email: $raw['commit']['author']['email'] ?? '',
                avatar: $raw['author']['avatar_url'] ?? null,
                raw: $raw['commit']['author'],
            );
        }

        $author = is_array($raw['author'] ?? null) ? $raw['author'] : [];

        return new Author(
            name: $author['name'] ?? '',
            email: $author['email'] ?? '',
            avatar: $author['avatar_url'] ?? null,
            raw: $author,
        );
    }

    /** @param array<string, mixed> $raw */
    private function commitDate(array $raw): string
    {
        return $raw['commit']['author']['date']
            ?? $raw['timestamp']
            ?? $raw['author']['date']
            ?? 'now';
    }
}
