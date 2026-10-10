<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Handles;

use RoundlyConsulting\Git\Dto\Activity;
use RoundlyConsulting\Git\Dto\Branch;
use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Comparison;
use RoundlyConsulting\Git\Dto\Contributor;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\FileContent;
use RoundlyConsulting\Git\Dto\Input\NewBranch;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Dto\Input\NewFile;
use RoundlyConsulting\Git\Dto\Input\NewPullRequest;
use RoundlyConsulting\Git\Dto\Input\NewRelease;
use RoundlyConsulting\Git\Dto\Input\NewTag;
use RoundlyConsulting\Git\Dto\Input\UpdatedFile;
use RoundlyConsulting\Git\Dto\Issue;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Interfaces\Provider;
use RoundlyConsulting\Git\Query\CommitQuery;
use RoundlyConsulting\Git\Webhooks\Webhooks;

/**
 * One repository on one provider — `Git::github()->repo('acme/app')`.
 *
 * A thin handle: every method is the matching {@see Provider} method with the path filled
 * in, so a custom driver and `Git::fake()` see exactly the calls the flat API would make.
 * The path is checked once, here, so nothing built from it can address another resource.
 */
final readonly class RepositoryHandle
{
    /**
     * @throws OutOfScopeException when the path could step outside the repository
     */
    public function __construct(
        private Provider $provider,
        private string $path,
    ) {
        PathGuard::repository($path);
    }

    public function path(): string
    {
        return $this->path;
    }

    public function get(): Repository
    {
        return $this->provider->repository($this->path);
    }

    /** @return Page<string> */
    public function branches(int $perPage = 30): Page
    {
        return $this->provider->branches($this->path, $perPage);
    }

    /**
     * One branch head, by its EXACT name: a tag, a sha or a prefix of a branch name is a
     * `404` `RequestException`, never another commit.
     *
     * @throws OutOfScopeException when the name could step outside the repository
     */
    public function branch(string $name): Branch
    {
        return $this->provider->branch($this->path, PathGuard::ref('branch', $name));
    }

    /** Create a branch; returns the new ref (`refs/heads/<name>`). */
    public function createBranch(NewBranch $data): string
    {
        return $this->provider->createBranch($this->path, $data);
    }

    public function commit(string $sha): Commit
    {
        return $this->provider->commit($this->path, PathGuard::ref('commit ref', $sha));
    }

    /** A chainable commit query — `->branch('main')->since(...)->lazy()`. */
    public function commits(): CommitQuery
    {
        return $this->provider->commits($this->path);
    }

    /** @return Page<PullRequest> */
    public function pullRequests(string $state = 'open', int $perPage = 30): Page
    {
        return $this->provider->pullRequests($this->path, $state, $perPage);
    }

    /**
     * A handle on one pull request of THIS repository — `->pullRequest(12)->merge()`.
     *
     * @throws OutOfScopeException when the number is below 1
     */
    public function pullRequest(int $number): PullRequestHandle
    {
        return new PullRequestHandle($this->provider, $this->path, $number);
    }

    public function createPullRequest(NewPullRequest $data): PullRequest
    {
        return $this->provider->createPullRequest($this->path, $data);
    }

    /** @return Page<Issue> */
    public function issues(string $state = 'open', int $perPage = 30): Page
    {
        return $this->provider->issues($this->path, $state, $perPage);
    }

    public function issue(int $number): Issue
    {
        return $this->provider->issue($this->path, $number);
    }

    /**
     * Comment on an issue or pull request of this repository, by its number. Say which
     * with `target:` — GitLab numbers issues and merge requests separately and refuses a
     * comment that does not; `->pullRequest($n)->comment()` sets it for you.
     */
    public function comment(NewComment $data): Comment
    {
        return $this->provider->comment($this->path, $data);
    }

    /** @return Page<Tag> */
    public function tags(int $perPage = 30): Page
    {
        return $this->provider->tags($this->path, $perPage);
    }

    public function createTag(NewTag $data): Tag
    {
        return $this->provider->createTag($this->path, $data);
    }

    /** @return Page<Release> */
    public function releases(int $perPage = 30): Page
    {
        return $this->provider->releases($this->path, $perPage);
    }

    public function release(string $tagOrId): Release
    {
        return $this->provider->release($this->path, PathGuard::ref('release tag or id', $tagOrId));
    }

    public function createRelease(NewRelease $data): Release
    {
        return $this->provider->createRelease($this->path, $data);
    }

    public function contents(string $filePath, ?string $ref = null): FileContent
    {
        return $this->provider->contents($this->path, PathGuard::file($filePath), $ref);
    }

    public function createFile(NewFile $data): Commit
    {
        PathGuard::file($data->path);

        return $this->provider->createFile($this->path, $data);
    }

    public function updateFile(UpdatedFile $data): Commit
    {
        PathGuard::file($data->path);

        return $this->provider->updateFile($this->path, $data);
    }

    public function compare(string $base, string $head): Comparison
    {
        return $this->provider->compare(
            $this->path,
            PathGuard::ref('base ref', $base),
            PathGuard::ref('head ref', $head),
        );
    }

    /** @return Page<Contributor> */
    public function contributors(int $perPage = 30): Page
    {
        return $this->provider->contributors($this->path, $perPage);
    }

    /**
     * The repository's activity feed, newest first — pushes, force pushes, branch creations
     * and deletions, merges — optionally for one ref (`main` or `refs/heads/main`). GitHub only.
     *
     * Cursor-paged: hand the page's `nextCursor` back as `$cursor` for the next one.
     *
     * @return Page<Activity>
     */
    public function activity(?string $ref = null, int $perPage = 30, ?string $cursor = null): Page
    {
        return $this->provider->activity($this->path, $ref, $perPage, $cursor);
    }

    /** @return array<string, int> */
    public function languages(): array
    {
        return $this->provider->languages($this->path);
    }

    /**
     * This repository's CI workflows (GitHub Actions): dispatch, runs, one run, jobs, cancel.
     * Forges without Actions answer `FeatureNotSupportedException`.
     */
    public function actions(): ActionsHandle
    {
        return new ActionsHandle($this->provider, $this->path);
    }

    /** Register, list and delete this repository's webhooks. */
    public function webhooks(): Webhooks
    {
        return new Webhooks($this->provider, $this->path);
    }

    /**
     * An authenticated `https` clone URL for this repository.
     *
     * The URL carries the credential's secret — hand it to a `git` subprocess, never to a
     * log. A GitHub App's own JWT is refused; use an installation token.
     */
    public function cloneUrl(string $username, Credentials $credentials): string
    {
        return $this->provider->cloneUrlForRepository($this->path, $username, $credentials);
    }
}
