<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Interfaces;

use Illuminate\Support\LazyCollection;
use RoundlyConsulting\Git\Batch\Batch;
use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Comparison;
use RoundlyConsulting\Git\Dto\Contributor;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\FeatureInfo;
use RoundlyConsulting\Git\Dto\FileContent;
use RoundlyConsulting\Git\Dto\Input\NewBranch;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Dto\Input\NewFile;
use RoundlyConsulting\Git\Dto\Input\NewPullRequest;
use RoundlyConsulting\Git\Dto\Input\NewRelease;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Input\NewReview;
use RoundlyConsulting\Git\Dto\Input\NewTag;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Input\UpdatedFile;
use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Dto\Issue;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\PullRequestReview;
use RoundlyConsulting\Git\Dto\PullRequestReviews;
use RoundlyConsulting\Git\Dto\RateLimitStatus;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\MergeMethod;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Handles\InstallationsHandle;
use RoundlyConsulting\Git\Handles\RepositoryHandle;
use RoundlyConsulting\Git\Query\CommitQuery;

/**
 * Everything a forge driver answers.
 *
 * Two layers live here. The flat, path-taking methods (`pullRequests($path, …)`,
 * `mergePullRequest($path, $number, …)`) are the DRIVER contract — what a custom driver
 * implements and `Testing\ProviderFake` doubles. Host code reads better through the
 * handles built on top of them, `repo($path)` and `installations()`, which fill the path
 * in and refuse anything that would step outside it; both layers run the same code.
 *
 * The rule for this file: if `BaseProvider` implements it publicly and it is not tagged
 * `@internal` (the batch plumbing), it belongs HERE. A method
 * that lives only on the concrete driver is unreachable through the `Provider` type
 * without an `instanceof`, is not covered by the `Testing\ProviderFake` double,
 * and — when a driver forgets to implement it — answers PHP's fatal "undefined method"
 * instead of `FeatureNotSupportedException`. Adding it here is what makes the fake's
 * gaps a compile error rather than a host application's runtime surprise.
 */
interface Provider
{
    public function name(): string;

    public function description(): string;

    public function providerName(): ProviderName;

    /** @return list<Feature> */
    public function features(): array;

    public function supports(Feature $feature): bool;

    /** @return array<string, bool> */
    public function capabilities(): array;

    public function supportsAll(Feature ...$features): bool;

    public function supportsAny(Feature ...$features): bool;

    /** @return list<FeatureInfo> */
    public function featureMatrix(): array;

    /** @return list<FeatureInfo> */
    public function featureInfo(): array;

    public function batch(): Batch;

    /**
     * A handle on one repository — every repository-scoped method below with the path
     * filled in: `->repo('acme/app')->pullRequest(12)->merge()`.
     */
    public function repo(string|Repository $repository): RepositoryHandle;

    /** The app-installation lookups: `Git::githubApp()->installations()->find($id)`. */
    public function installations(): InstallationsHandle;

    public function createWebhook(string $path, NewWebhook $data): Webhook;

    public function deleteWebhook(string $path, string $id): void;

    /** @return list<Webhook> */
    public function listWebhooks(string $path): array;

    /** @return list<class-string<Credentials>> */
    public function authenticationMethods(): array;

    public function authenticate(Credentials $credentials): self;

    public function isAuthenticated(): bool;

    public function rateLimit(): ?RateLimitStatus;

    public function user(): Owner;

    /** @return Page<Repository> */
    public function repositories(int $perPage = 30): Page;

    /** @return LazyCollection<int, Repository> */
    public function allRepositories(int $perPage = 30): LazyCollection;

    public function repository(string $path): Repository;

    /** @return Page<string> */
    public function branches(string $path, int $perPage = 30): Page;

    public function commit(string $path, string $commit): Commit;

    /** A chainable commit query (branch / author / path / since / until). */
    public function commits(string $path): CommitQuery;

    /** @return Page<PullRequest> */
    public function pullRequests(string $path, string $state = 'open', int $perPage = 30): Page;

    public function pullRequest(string $path, int $number): PullRequest;

    /** @return Page<Issue> */
    public function issues(string $path, string $state = 'open', int $perPage = 30): Page;

    public function issue(string $path, int $number): Issue;

    /** @return Page<Tag> */
    public function tags(string $path, int $perPage = 30): Page;

    /** @return Page<Release> */
    public function releases(string $path, int $perPage = 30): Page;

    public function release(string $path, string $tagOrId): Release;

    public function contents(string $path, string $filePath, ?string $ref = null): FileContent;

    public function compare(string $path, string $base, string $head): Comparison;

    /** @return Page<Contributor> */
    public function contributors(string $path, int $perPage = 30): Page;

    /** @return array<string, int> */
    public function languages(string $path): array;

    /** @return Page<Repository> */
    public function searchRepositories(string $query, int $perPage = 30): Page;

    public function createRepository(NewRepository $data): Repository;

    public function createBranch(string $path, NewBranch $data): string;

    public function createFile(string $path, NewFile $data): Commit;

    public function updateFile(string $path, UpdatedFile $data): Commit;

    public function createPullRequest(string $path, NewPullRequest $data): PullRequest;

    /** Close a pull request WITHOUT merging it. It never reopens one. */
    public function closePullRequest(string $path, int $number): PullRequest;

    /** Approve a pull request as the authenticated account; returns the review's own state. */
    public function approvePullRequest(string $path, int $number, ?string $body = null): string;

    /**
     * Publish a review — a verdict, a summary, and inline comments on the diff.
     *
     * The general form of {@see approvePullRequest()}, and the one an account that
     * AUTHORS pull requests can use on its own work: a forge refuses `APPROVE` and
     * `REQUEST_CHANGES` from the author (`422`) but takes a `COMMENT` review from
     * anyone.
     *
     * **An inline comment anchored off the diff is a `422` too**, and it is a different
     * thing entirely — the review was written against a line nobody changed. Both arrive
     * as `RequestException`; the caller tells them apart by whether it sent comments.
     */
    public function reviewPullRequest(string $path, int $number, NewReview $data): PullRequestReview;

    /**
     * Every review left on a pull request, with the inline comments they anchored.
     *
     * Walked to the end (or to `$maxPages`), because a forge serves these ASCENDING: one
     * page of a long thread is its OLDEST reviews, and a caller reading "the latest
     * verdict" off it would act on the wrong one.
     */
    public function pullRequestReviews(string $path, int $number, int $perPage = 100, int $maxPages = 5): PullRequestReviews;

    /**
     * Merge a pull request; returns the merge commit sha.
     *
     * A merge the provider refuses THROWS rather than returning a falsy result — see the
     * GitHub driver for why a `merged: false` return would be a shape that never arrives.
     */
    public function mergePullRequest(
        string $path,
        int $number,
        MergeMethod $method = MergeMethod::Merge,
        ?string $sha = null,
        ?string $title = null,
        ?string $message = null,
    ): string;

    public function comment(string $path, NewComment $data): Comment;

    public function createRelease(string $path, NewRelease $data): Release;

    public function createTag(string $path, NewTag $data): Tag;

    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string;

    /**
     * The repositories reachable by the credential's own installation.
     *
     * On the shared interface (rather than only on the GitHub provider) so a host
     * application can drive it through `Git::fake()`. Providers without app
     * installations answer `FeatureNotSupportedException`.
     *
     * @return Page<Repository>
     */
    public function installationRepositories(int $perPage = 30): Page;

    /** @return LazyCollection<int, Repository> */
    public function allInstallationRepositories(int $perPage = 30): LazyCollection;

    /** One app installation, looked up as the app itself. */
    public function installation(string $id): Installation;

    /**
     * Every account this app is installed on, looked up as the app itself.
     *
     * On the shared interface for the same reason `installation()` is: `Git::githubApp()`
     * is typed to THIS interface, so a method only the concrete GitHub provider declares is
     * unreachable without an `instanceof` narrowing — and a provider without app installations
     * answers a fatal "undefined method" instead of `FeatureNotSupportedException`.
     *
     * @return Page<Installation>
     */
    public function listInstallations(int $perPage = 30): Page;

    /** This app's installation on an organization, looked up as the app itself. */
    public function organizationInstallation(string $organization): Installation;

    /** This app's installation on a user account, looked up as the app itself. */
    public function userInstallation(string $login): Installation;

    /**
     * Where to send a human to install this provider's app, carrying `state`.
     *
     * On the shared interface for the same reason the two above are: it is the entry point
     * of a consumer's redirect flow, so it must be drivable through `Git::fake()` and
     * callable on the `Provider` type without an `instanceof` narrowing at every call site.
     */
    public function installUrl(?string $state = null): string;
}
