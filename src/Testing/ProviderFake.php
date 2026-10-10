<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Testing;

use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use RoundlyConsulting\Git\Batch\Batch;
use RoundlyConsulting\Git\Batch\BatchResult;
use RoundlyConsulting\Git\Concerns\ProvidesHandles;
use RoundlyConsulting\Git\Contracts\ListsWebhookEvents;
use RoundlyConsulting\Git\Contracts\RefreshableCredentials;
use RoundlyConsulting\Git\Dto\Activity;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Branch;
use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Comparison;
use RoundlyConsulting\Git\Dto\Contributor;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Credentials\GithubApp;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\DispatchedWorkflow;
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
use RoundlyConsulting\Git\Dto\Input\NewWorkflowDispatch;
use RoundlyConsulting\Git\Dto\Input\UpdatedFile;
use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Dto\Issue;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\PullRequestReview;
use RoundlyConsulting\Git\Dto\PullRequestReviewComment;
use RoundlyConsulting\Git\Dto\PullRequestReviews;
use RoundlyConsulting\Git\Dto\RateLimitStatus;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Dto\WorkflowJob;
use RoundlyConsulting\Git\Dto\WorkflowRun;
use RoundlyConsulting\Git\Enums\ComparisonStatus;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\MergeMethod;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ResourceState;
use RoundlyConsulting\Git\Enums\ReviewEvent;
use RoundlyConsulting\Git\Enums\WorkflowStatus;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Handles\PathGuard;
use RoundlyConsulting\Git\Interfaces\Provider;
use RoundlyConsulting\Git\Providers\BaseProvider;
use RoundlyConsulting\Git\Query\CommitQuery;
use RoundlyConsulting\Git\Query\WorkflowJobQuery;
use RoundlyConsulting\Git\Query\WorkflowRunQuery;
use RoundlyConsulting\Git\Support\Settings;
use RuntimeException;

/**
 * In-memory provider double for testing host applications without real HTTP.
 *
 * It answers the WHOLE {@see Provider} contract, not the handful of methods a test
 * happened to need first: a fake that stops short of the real surface fails with PHP's
 * "undefined method" the moment a host application grows past it, which reads as a bug
 * in the host rather than a gap in the double.
 *
 * Three conventions, so what a method does is predictable without reading it:
 *
 * - **List reads** answer an EMPTY page when nothing is seeded. An empty list is a real
 *   provider answer, so a default cannot mislead.
 * - **Single-resource reads** THROW, naming the seeder to call. There is no honest
 *   default for "the repository under test", and returning a placeholder turns a missing
 *   `seedRepository()` into an assertion failure three lines later.
 * - **Writes** synthesize their result from the input they were given, so a host can
 *   drive a whole create/close/merge flow with no seeding at all.
 *
 * Every call first passes the feature matrix of the REAL driver it stands in for — the
 * list is read off that driver, not restated here — so a fake Bitbucket refuses a merge
 * with the same FeatureNotSupportedException production throws, and a host test cannot
 * pass against a flow its forge rejects. The same goes for the driver's input rules
 * (comment targets, commit filters, repository and webhook input): the fake runs the
 * driver's own `validate*()` checks, not a copy of them.
 */
final class ProviderFake implements ListsWebhookEvents, Provider
{
    use ProvidesHandles;

    /** Shared by every per-call copy of this driver ({@see fresh()}). */
    private readonly SeedStore $seeds;

    /** The credential this copy was handed — per call, as on the real drivers. */
    private ?Credentials $authentication = null;

    /**
     * @param  list<Feature>  $features  the real driver's feature list, which this fake enforces
     * @param  Provider|null  $driver  the real driver, whose input checks and credential types this fake applies
     */
    public function __construct(
        private readonly ProviderName $name,
        private readonly GitFake $git,
        private readonly array $features,
        private readonly ?Provider $driver = null,
    ) {
        $this->seeds = new SeedStore;
    }

    /**
     * An unauthenticated copy that shares this driver's seeds — what each `Git::github()`
     * call hands out, so one call's credential never leaks into another's.
     *
     * @internal the fake manager's per-call driver.
     */
    public function fresh(): self
    {
        $copy = clone $this;
        $copy->authentication = null;

        return $copy;
    }

    /** @param BatchResult<mixed> $result */
    public function seedBatch(string $method, BatchResult $result): self
    {
        $this->seeds->batches[$method] = $result;

        return $this;
    }

    /** @param list<Repository> $repositories */
    public function seedRepositories(array $repositories): self
    {
        return $this->seed('repositories', $repositories);
    }

    /** @param list<Commit> $commits */
    public function seedCommits(array $commits): self
    {
        return $this->seed('commits', $commits);
    }

    public function seedCommit(Commit $commit): self
    {
        return $this->seed('commit', $commit);
    }

    public function seedUser(Owner $user): self
    {
        return $this->seed('user', $user);
    }

    public function seedRepository(Repository $repository): self
    {
        return $this->seed('repository', $repository);
    }

    /** The repository `createRepository()` answers with, instead of one synthesized from the input. */
    public function seedCreatedRepository(Repository $repository): self
    {
        return $this->seed('createRepository', $repository);
    }

    /** @param list<string> $branches */
    public function seedBranches(array $branches): self
    {
        return $this->seed('branches', $branches);
    }

    /** One branch head `branch()` answers for its name; seed each branch a test reads. */
    public function seedBranch(Branch $branch): self
    {
        /** @var array<string, Branch> $branches */
        $branches = $this->seeds->values['branch'] ?? [];
        $branches[$branch->name] = $branch;

        return $this->seed('branch', $branches);
    }

    /**
     * What `activity()` pages through — in the order given, so seed it newest first, as
     * GitHub sends it.
     *
     * @param  list<Activity>  $activity
     */
    public function seedActivity(array $activity): self
    {
        return $this->seed('activity', $activity);
    }

    /**
     * The runs `runs()` and `run()` read — replacing any seeded or dispatched before.
     *
     * @param  list<WorkflowRun>  $runs
     */
    public function seedWorkflowRuns(array $runs): self
    {
        return $this->seed('workflowRuns', $runs);
    }

    /**
     * One run, upserted by id — what the next `run()` poll reads, and how a test moves a
     * dispatched run on (`in_progress`, then `completed`).
     */
    public function seedWorkflowRun(WorkflowRun $run): self
    {
        $runs = $this->workflowRunList();

        foreach ($runs as $index => $seeded) {
            if ($seeded->id === $run->id) {
                $runs[$index] = $run;

                return $this->seed('workflowRuns', $runs);
            }
        }

        $runs[] = $run;

        return $this->seed('workflowRuns', $runs);
    }

    /**
     * The jobs of one run, across its attempts (`runAttempt`).
     *
     * @param  list<WorkflowJob>  $jobs
     */
    public function seedWorkflowJobs(int|string $runId, array $jobs): self
    {
        /** @var array<string, list<WorkflowJob>> $seeded */
        $seeded = $this->seeds->values['workflowJobs'] ?? [];
        $seeded[(string) $runId] = $jobs;

        return $this->seed('workflowJobs', $seeded);
    }

    /**
     * Answer `dispatch()` as GitHub Enterprise Server does — without the run it started — so
     * a host can test finding the run with a `runs()` query. The run is still created.
     */
    public function seedDispatchWithoutRunDetails(): self
    {
        return $this->seed('dispatchWithoutRunDetails', true);
    }

    /** @param list<PullRequest> $pullRequests */
    public function seedPullRequests(array $pullRequests): self
    {
        return $this->seed('pullRequests', $pullRequests);
    }

    public function seedPullRequest(PullRequest $pullRequest): self
    {
        return $this->seed('pullRequest', $pullRequest);
    }

    /** @param list<Issue> $issues */
    public function seedIssues(array $issues): self
    {
        return $this->seed('issues', $issues);
    }

    public function seedIssue(Issue $issue): self
    {
        return $this->seed('issue', $issue);
    }

    /** @param list<Tag> $tags */
    public function seedTags(array $tags): self
    {
        return $this->seed('tags', $tags);
    }

    /** @param list<Release> $releases */
    public function seedReleases(array $releases): self
    {
        return $this->seed('releases', $releases);
    }

    public function seedRelease(Release $release): self
    {
        return $this->seed('release', $release);
    }

    public function seedContents(FileContent $contents): self
    {
        return $this->seed('contents', $contents);
    }

    public function seedComparison(Comparison $comparison): self
    {
        return $this->seed('comparison', $comparison);
    }

    /** @param list<Contributor> $contributors */
    public function seedContributors(array $contributors): self
    {
        return $this->seed('contributors', $contributors);
    }

    /** @param array<string, int> $languages */
    public function seedLanguages(array $languages): self
    {
        return $this->seed('languages', $languages);
    }

    /** @param list<Repository> $repositories */
    public function seedSearchResults(array $repositories): self
    {
        return $this->seed('searchRepositories', $repositories);
    }

    public function seedComment(Comment $comment): self
    {
        return $this->seed('comment', $comment);
    }

    /** The sha `mergePullRequest()` answers with. */
    public function seedMergeCommit(string $sha): self
    {
        return $this->seed('mergeCommit', $sha);
    }

    /** The review state `approvePullRequest()` answers with (GitHub sends `APPROVED`). */
    public function seedApprovalState(string $state): self
    {
        return $this->seed('approvalState', $state);
    }

    /** The review `reviewPullRequest()` answers with, instead of one built from the input. */
    public function seedSubmittedReview(PullRequestReview $review): self
    {
        return $this->seed('pullRequestReview', $review);
    }

    /**
     * What `pullRequestReviews()` reads back.
     *
     * The two halves are seeded separately because they arrive from two endpoints and a
     * host asserting on "changes requested with three findings" needs to seed the
     * findings independently of the verdict that summarizes them.
     *
     * @param  list<PullRequestReview>  $reviews
     * @param  list<PullRequestReviewComment>  $comments
     */
    public function seedPullRequestReviews(array $reviews, array $comments = []): self
    {
        return $this->seed('pullRequestReviews', $reviews)->seed('pullRequestReviewComments', $comments);
    }

    public function seedInstallation(Installation $installation): self
    {
        return $this->seed('installation', $installation);
    }

    /** @param list<Installation> $installations */
    public function seedInstallations(array $installations): self
    {
        return $this->seed('installations', $installations);
    }

    /** @param list<Webhook> $webhooks */
    public function seedWebhooks(array $webhooks): self
    {
        return $this->seed('webhooks', $webhooks);
    }

    /** The webhook `createWebhook()` answers with, instead of one synthesized from the input. */
    public function seedCreatedWebhook(Webhook $webhook): self
    {
        return $this->seed('createWebhook', $webhook);
    }

    public function name(): string
    {
        return $this->name->label();
    }

    public function description(): string
    {
        return "{$this->name()} Provider";
    }

    public function providerName(): ProviderName
    {
        return $this->name;
    }

    /** @return list<Feature> */
    public function features(): array
    {
        return $this->features;
    }

    public function supports(Feature $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    /**
     * Throw what the real driver throws for a feature its forge lacks.
     *
     * @internal the fake drivers' (and their batch's) own guard.
     *
     * @throws FeatureNotSupportedException
     */
    public function ensureSupported(Feature $feature): void
    {
        if (! $this->supports($feature)) {
            throw FeatureNotSupportedException::for(feature: $feature->value, provider: $this->name());
        }
    }

    /**
     * The credential types the real driver takes.
     *
     * @return list<class-string<Credentials>>
     */
    public function authenticationMethods(): array
    {
        return $this->driver?->authenticationMethods() ?? [];
    }

    /**
     * Authenticate this copy — refusing a credential type the real driver refuses.
     *
     * @throws InvalidCredentialsException when the real driver does not take this type
     */
    public function authenticate(Credentials $credentials): self
    {
        $supported = $this->authenticationMethods();

        if ($this->driver !== null && ! in_array($credentials::class, $supported, true)) {
            throw InvalidCredentialsException::unsupported($this->name(), $credentials::class, $supported);
        }

        $this->authentication = $credentials;

        return $this;
    }

    public function isAuthenticated(): bool
    {
        return $this->authentication !== null;
    }

    public function rateLimit(): ?RateLimitStatus
    {
        return null;
    }

    public function user(): Owner
    {
        $this->record('user', []);

        /** @var Owner */
        return $this->seeds->values['user'] ?? new Owner(id: 'fake', name: 'fake', avatar: null);
    }

    /** @return Page<Repository> */
    public function repositories(int $perPage = 30): Page
    {
        $this->ensureSupported(Feature::ListRepositories);

        $size = $this->pageSize($perPage);

        $this->record('repositories', [$perPage]);

        return $this->page($this->list('repositories'), $size);
    }

    /** @return LazyCollection<int, Repository> */
    public function allRepositories(int $perPage = 30): LazyCollection
    {
        $this->ensureSupported(Feature::ListRepositories);

        $this->record('allRepositories', [$perPage]);

        // Lazy like the real walk, which refuses a page size below 1 on its first page.
        return LazyCollection::make(function () use ($perPage) {
            $this->pageSize($perPage);

            yield from $this->list('repositories');
        });
    }

    /**
     * The repositories one installation can reach.
     *
     * Seeded from the SAME bucket as `repositories()` on purpose: a host application
     * swapping a PAT for a GitHub App changes which endpoint it calls, not which
     * repositories the test is about, and two buckets would let a test seed one and
     * assert the other.
     *
     * @return Page<Repository>
     */
    public function installationRepositories(int $perPage = 30): Page
    {
        $this->ensureSupported(Feature::ListInstallationRepositories);
        $this->guardCredential(GithubAppToken::class);

        $size = $this->pageSize($perPage);

        $this->record('installationRepositories', [$perPage]);

        return $this->page($this->list('repositories'), $size);
    }

    /** @return LazyCollection<int, Repository> */
    public function allInstallationRepositories(int $perPage = 30): LazyCollection
    {
        $this->ensureSupported(Feature::ListInstallationRepositories);
        $this->guardCredential(GithubAppToken::class);

        $this->record('allInstallationRepositories', [$perPage]);

        // Lazy like the real walk, which refuses a page size below 1 on its first page.
        return LazyCollection::make(function () use ($perPage) {
            $this->pageSize($perPage);

            yield from $this->list('repositories');
        });
    }

    public function installation(string $id): Installation
    {
        $this->ensureSupported(Feature::FindInstallation);
        $this->guardCredential(GithubApp::class);

        $this->record('installation', [$id]);

        return $this->seededInstallation();
    }

    /**
     * Every seeded installation.
     *
     * Falls back to the single `seedInstallation()` bucket so a test that seeded one
     * installation and then listed them gets that installation rather than an empty page
     * — the same reasoning as `installationRepositories()` reusing the repository bucket.
     *
     * @return Page<Installation>
     */
    public function listInstallations(int $perPage = 30): Page
    {
        $this->ensureSupported(Feature::ListInstallations);
        $this->guardCredential(GithubApp::class);

        $size = $this->pageSize($perPage);

        $this->record('listInstallations', [$perPage]);

        /** @var list<Installation> $items */
        $items = $this->seeds->values['installations']
            ?? (isset($this->seeds->values['installation']) ? [$this->seededInstallation()] : []);

        return $this->page($items, $size);
    }

    public function organizationInstallation(string $organization): Installation
    {
        $this->ensureSupported(Feature::FindInstallation);
        $this->guardCredential(GithubApp::class);

        $this->record('organizationInstallation', [$organization]);

        return $this->seededInstallation();
    }

    public function userInstallation(string $login): Installation
    {
        $this->ensureSupported(Feature::FindInstallation);
        $this->guardCredential(GithubApp::class);

        $this->record('userInstallation', [$login]);

        return $this->seededInstallation();
    }

    private function seededInstallation(): Installation
    {
        /** @var Installation */
        return $this->required('installation', 'seedInstallation()');
    }

    /**
     * The install URL, matching the real provider's treatment of an EMPTY state.
     *
     * With no `app.slug` configured it throws `missingAppConfig()`, as the real provider
     * does — there is no fallback slug a host test could assert against.
     *
     * `''` is what a consumer passes when it built a state and got back an empty string.
     * The real provider omits the parameter; a fake that appended `?state=` instead would
     * let a test assert a URL production never produces.
     */
    public function installUrl(?string $state = null): string
    {
        // The real drivers only implement it where app installations exist; elsewhere the
        // base driver refuses with the method's name, so the fake does the same.
        if (! $this->supports(Feature::FindInstallation)) {
            throw FeatureNotSupportedException::for(feature: 'installUrl', provider: $this->name());
        }

        // The real driver needs the app's slug to build the URL and names the missing key.
        $slug = Settings::filled(config("git.providers.{$this->name->key()}.app.slug"))
            ?? throw InvalidCredentialsException::missingAppConfig($this->name->key(), 'slug');

        $this->record('installUrl', [$state]);

        // Encoded like the real driver, so a test never asserts a URL production can't build.
        $url = 'https://fake/apps/'.rawurlencode($slug).'/installations/new';

        return $state === null || $state === '' ? $url : $url.'?state='.urlencode($state);
    }

    public function repository(string $path): Repository
    {
        $this->ensureSupported(Feature::FindRepository);

        $this->record('repository', [$path]);

        /** @var Repository */
        return $this->required('repository', 'seedRepository()');
    }

    /** @return Page<string> */
    public function branches(string $path, int $perPage = 30): Page
    {
        $this->ensureSupported(Feature::ListRepositoryBranches);

        $size = $this->pageSize($perPage);

        $this->record('branches', [$path, $perPage]);

        return $this->page($this->list('branches'), $size);
    }

    /** The branch seeded under that name — there is no honest default head. */
    public function branch(string $path, string $name): Branch
    {
        $this->ensureSupported(Feature::FindBranch);

        $this->record('branch', [$path, $name]);

        /** @var array<string, Branch> $branches */
        $branches = $this->seeds->values['branch'] ?? [];

        return $branches[$name] ?? throw $this->unseeded("branch [{$name}]", 'seedBranch()');
    }

    public function commit(string $path, string $commit): Commit
    {
        $this->ensureSupported(Feature::FindCommit);

        $this->record('commit', [$path, $commit]);

        /** @var Commit */
        return $this->required('commit', 'seedCommit()');
    }

    /**
     * A commit query over the seeded commits.
     *
     * The filters a caller chains (`->branch()`, `->since()`) are applied by the REAL
     * provider's query translation, which the fake has no HTTP layer to run — so they
     * are recorded rather than honoured, and the pages walk the seeded set. A filter
     * the forge cannot apply at all (Bitbucket's author and dates) is refused, as the
     * real driver refuses it.
     */
    public function commits(string $path): CommitQuery
    {
        $this->ensureSupported(Feature::ListCommits);

        $this->record('commits', [$path]);

        return new CommitQuery(function (array $filters, int $page, int $perPage): Page {
            $this->driverChecks()?->validateCommitFilters($filters);

            $size = $this->pageSize($perPage);

            $this->record('commits.get', [$filters, $page, $perPage]);

            return $this->page($this->list('commits'), $size, $page);
        });
    }

    /** @return Page<PullRequest> */
    public function pullRequests(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->ensureSupported(Feature::ListPullRequests);

        $size = $this->driverChecks()?->pullRequestPageSize($perPage) ?? $this->pageSize($perPage);

        $this->record('pullRequests', [$path, $state, $perPage]);

        return $this->page($this->list('pullRequests'), $size);
    }

    public function pullRequest(string $path, int $number): PullRequest
    {
        $this->ensureSupported(Feature::FindPullRequest);

        $this->record('pullRequest', [$path, $number]);

        return $this->seededPullRequest($number);
    }

    /** @return Page<Issue> */
    public function issues(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->ensureSupported(Feature::ListIssues);

        $size = $this->pageSize($perPage);

        $this->record('issues', [$path, $state, $perPage]);

        return $this->page($this->list('issues'), $size);
    }

    public function issue(string $path, int $number): Issue
    {
        $this->ensureSupported(Feature::FindIssue);

        $this->record('issue', [$path, $number]);

        /** @var Issue */
        return $this->seeds->values['issue']
            ?? $this->list('issues')[0]
            ?? throw $this->unseeded('issue', 'seedIssue()');
    }

    /** @return Page<Tag> */
    public function tags(string $path, int $perPage = 30): Page
    {
        $this->ensureSupported(Feature::ListTags);

        $size = $this->pageSize($perPage);

        $this->record('tags', [$path, $perPage]);

        return $this->page($this->list('tags'), $size);
    }

    /** @return Page<Release> */
    public function releases(string $path, int $perPage = 30): Page
    {
        $this->ensureSupported(Feature::ListReleases);

        $size = $this->pageSize($perPage);

        $this->record('releases', [$path, $perPage]);

        return $this->page($this->list('releases'), $size);
    }

    public function release(string $path, string $tagOrId): Release
    {
        $this->ensureSupported(Feature::FindRelease);

        $this->record('release', [$path, $tagOrId]);

        /** @var Release */
        return $this->seeds->values['release']
            ?? $this->list('releases')[0]
            ?? throw $this->unseeded('release', 'seedRelease()');
    }

    public function contents(string $path, string $filePath, ?string $ref = null): FileContent
    {
        $this->ensureSupported(Feature::FileContents);

        $this->record('contents', [$path, $filePath, $ref]);

        /** @var FileContent */
        return $this->required('contents', 'seedContents()');
    }

    public function compare(string $path, string $base, string $head): Comparison
    {
        $this->ensureSupported(Feature::Compare);

        $this->record('compare', [$path, $base, $head]);

        /** @var Comparison */
        return $this->seeds->values['comparison'] ?? new Comparison(
            base: $base,
            head: $head,
            aheadBy: 0,
            behindBy: 0,
            files: [],
            status: ComparisonStatus::Identical,
            totalCommits: 0,
            commits: [],
        );
    }

    /** @return Page<Contributor> */
    public function contributors(string $path, int $perPage = 30): Page
    {
        $this->ensureSupported(Feature::ListContributors);

        $size = $this->pageSize($perPage);

        $this->record('contributors', [$path, $perPage]);

        return $this->page($this->list('contributors'), $size);
    }

    /**
     * The seeded activity for the ref (`main` reads as `refs/heads/main`, as on GitHub),
     * paged by an opaque cursor this fake hands out (`fake:<offset>`).
     *
     * @return Page<Activity>
     *
     * @throws InvalidArgumentException for a cursor this fake never handed out
     */
    public function activity(string $path, ?string $ref = null, int $perPage = 30, ?string $cursor = null): Page
    {
        $this->ensureSupported(Feature::RepositoryActivity);

        $size = $this->pageSize($perPage);
        $offset = 0;

        if ($cursor !== null) {
            if (preg_match('/^fake:(\d+)$/', $cursor, $match) !== 1) {
                throw new InvalidArgumentException("[{$cursor}] is not a cursor the fake handed out; pass a page's nextCursor back.");
            }

            $offset = (int) $match[1];
        }

        $this->record('activity', [$path, $ref, $perPage, $cursor]);

        $wanted = $ref === null ? null : $this->fullRef($ref);

        /** @var list<Activity> $seeded */
        $seeded = $this->list('activity');
        $matching = array_values(array_filter(
            $seeded,
            fn (Activity $activity): bool => $wanted === null || $this->fullRef($activity->ref) === $wanted,
        ));

        $more = count($matching) > $offset + $size;

        return new Page(
            items: array_slice($matching, $offset, $size),
            perPage: $size,
            page: 1,
            hasMore: $more,
            nextCursor: $more ? 'fake:'.($offset + $size) : null,
        );
    }

    /** `main` as GitHub reads it in a ref filter: `refs/heads/main`. */
    private function fullRef(string $ref): string
    {
        return str_starts_with($ref, 'refs/') ? $ref : "refs/heads/{$ref}";
    }

    /** @return array<string, int> */
    public function languages(string $path): array
    {
        $this->ensureSupported(Feature::Languages);

        $this->record('languages', [$path]);

        /** @var array<string, int> */
        return $this->seeds->values['languages'] ?? [];
    }

    /**
     * Search results, falling back to the plain repository bucket.
     *
     * A host that seeded repositories and then searched is testing its own handling of
     * results, not the provider's ranking — the fallback is what keeps that test from
     * asserting against an empty page it never asked for.
     *
     * @return Page<Repository>
     */
    public function searchRepositories(string $query, int $perPage = 30): Page
    {
        $this->ensureSupported(Feature::SearchRepositories);

        $size = $this->pageSize($perPage);

        $this->record('searchRepositories', [$query, $perPage]);

        /** @var list<Repository> $items */
        $items = $this->seeds->values['searchRepositories'] ?? $this->list('repositories');

        return $this->page($items, $size);
    }

    public function createRepository(NewRepository $data): Repository
    {
        $this->ensureSupported(Feature::CreateRepository);

        if ($data->template !== null) {
            $this->ensureSupported(Feature::GenerateFromTemplate);
        }

        $this->driverChecks()?->validateNewRepository($data);
        $this->guardAuthenticated();

        $this->record('createRepository', [$data]);

        // The seeded override still wins outright — a consumer that pinned the answer
        // gets it whatever the input said.
        /** @var Repository */
        return $this->seeds->values['createRepository'] ?? new Repository(
            provider: $this->name,
            id: 'fake',
            path: $data->owner !== null ? "{$data->owner}/{$data->name}" : $data->name,
            name: $data->name,
            description: $data->description,
            // Mirrors the real providers: a repository generated from a template or
            // initialized has a branch, and an EMPTY one has none. Reported as '' rather
            // than 'main' — the same stand-in `BitbucketMapper` uses for a repository
            // with no main branch — so a consumer that clones the fake's answer hits the
            // same wall it would in production instead of a branch that does not exist.
            defaultBranch: $data->defaultBranch
                ?? (($data->autoInit || $data->template !== null) ? 'main' : ''),
            owner: new Owner(id: 'fake', name: $data->owner ?? 'fake', avatar: null),
            createdAt: Carbon::now(),
            lastActivityAt: Carbon::now(),
            private: $data->private,
        );
    }

    /**
     * A dispatch, answered like github.com: it CREATES the queued run it reports, so a host
     * can drive dispatch → find → poll → cancel against the fake.
     *
     * The run's id is the largest numeric seeded id + 1 (1 on an empty fake); its `path` is
     * `.github/workflows/<file>`, and the workflow id is borrowed from a seeded run of the
     * same workflow ('' when none is seeded) — or the other way round for a workflow named by
     * id. After {@see seedDispatchWithoutRunDetails()} the answer carries no run id, as on
     * GitHub Enterprise Server, but the run is created all the same, so the fallback finder
     * can be tested.
     */
    public function dispatchWorkflow(string $path, NewWorkflowDispatch $data): DispatchedWorkflow
    {
        $this->ensureSupported(Feature::DispatchWorkflow);
        $this->guardAuthenticated();

        $dispatchedAt = Carbon::now();

        $this->record('dispatchWorkflow', [$path, $data]);

        $runs = $this->workflowRunList();
        $id = (string) (max([0, ...array_map(
            fn (WorkflowRun $run): int => (int) $run->id,
            array_filter($runs, fn (WorkflowRun $run): bool => ctype_digit($run->id)),
        )]) + 1);
        $sibling = $this->firstRunOf($runs, $data->workflow);
        $branch = preg_replace('#^refs/(heads|tags)/#', '', $data->ref) ?? $data->ref;
        $url = "https://fake/{$path}/actions/runs/{$id}";

        /** @var array<string, Branch> $branches */
        $branches = $this->seeds->values['branch'] ?? [];

        $this->seedWorkflowRun(new WorkflowRun(
            provider: $this->name,
            id: $id,
            workflowId: ctype_digit($data->workflow) ? $data->workflow : ($sibling->workflowId ?? ''),
            displayTitle: $data->workflow,
            status: WorkflowStatus::Queued,
            event: 'workflow_dispatch',
            path: ctype_digit($data->workflow) ? ($sibling->path ?? '') : ".github/workflows/{$data->workflow}",
            headSha: isset($branches[$branch]) ? $branches[$branch]->sha : 'fake-sha',
            createdAt: $dispatchedAt,
            updatedAt: $dispatchedAt,
            headBranch: $branch,
            headRepository: $path,
            runNumber: max([0, ...array_map(
                fn (WorkflowRun $run): int => $run->runNumber,
                array_filter($runs, fn (WorkflowRun $run): bool => $this->runOfWorkflow($run, $data->workflow)),
            )]) + 1,
            url: $url,
            // What GitHub reports for a run that has not started: its creation time.
            runStartedAt: $dispatchedAt,
        ));

        $details = ! isset($this->seeds->values['dispatchWithoutRunDetails']);

        return new DispatchedWorkflow(
            provider: $this->name,
            workflow: $data->workflow,
            ref: $data->ref,
            runId: $details ? $id : null,
            apiUrl: $details ? "https://fake/repos/{$path}/actions/runs/{$id}" : null,
            url: $details ? $url : null,
            dispatchedAt: $dispatchedAt,
        );
    }

    /**
     * The seeded runs, newest first, with every filter the real query sends APPLIED — an
     * ignored filter would hand back another workflow's run in exactly the find-my-run flow
     * this exists for. The workflow matches a run's `path` file (an `@ref` suffix dropped)
     * or, given as a number, its `workflowId`; `status` matches a status or a conclusion, as
     * on GitHub. `excludePullRequests()` shapes GitHub's payload only, so it filters nothing.
     */
    public function workflowRuns(string $path, ?string $workflow = null): WorkflowRunQuery
    {
        $this->ensureSupported(Feature::ListWorkflowRuns);

        $this->record('workflowRuns', [$path, $workflow]);

        return new WorkflowRunQuery(function (array $filters, int $page, int $perPage) use ($workflow): Page {
            $size = $this->pageSize($perPage);

            $this->record('workflowRuns.get', [$filters, $page, $perPage]);

            $runs = array_values(array_filter(
                $this->workflowRunList(),
                fn (WorkflowRun $run): bool => ($workflow === null || $this->runOfWorkflow($run, $workflow)) && $this->runMatches($run, $filters),
            ));

            usort($runs, fn (WorkflowRun $a, WorkflowRun $b): int => ($b->createdAt <=> $a->createdAt) ?: ((int) $b->id <=> (int) $a->id));

            return $this->page($runs, $size, $page, total: count($runs));
        });
    }

    public function workflowRun(string $path, string $id): WorkflowRun
    {
        $this->ensureSupported(Feature::FindWorkflowRun);

        $this->record('workflowRun', [$path, $id]);

        return $this->seededRun($id) ?? throw $this->unseeded("workflow run [{$id}]", 'seedWorkflowRun()');
    }

    /**
     * The jobs seeded for the run: of its latest attempt by default, of every attempt after
     * `allAttempts()`, of one after `attempt($n)`.
     */
    public function workflowJobs(string $path, string $runId): WorkflowJobQuery
    {
        $this->ensureSupported(Feature::ListWorkflowJobs);

        $this->record('workflowJobs', [$path, $runId]);

        return new WorkflowJobQuery(function (array $filters, int $page, int $perPage) use ($runId): Page {
            $size = $this->pageSize($perPage);

            $this->record('workflowJobs.get', [$filters, $page, $perPage]);

            /** @var array<string, list<WorkflowJob>> $seeded */
            $seeded = $this->seeds->values['workflowJobs'] ?? [];
            $jobs = $seeded[$runId] ?? [];

            $attempt = match (true) {
                isset($filters['attempt']) => (int) $filters['attempt'],
                ($filters['filter'] ?? null) === 'all' => null,
                default => max([0, ...array_map(fn (WorkflowJob $job): int => $job->runAttempt, $jobs)]),
            };

            $jobs = array_values(array_filter($jobs, fn (WorkflowJob $job): bool => $attempt === null || $job->runAttempt === $attempt));

            return $this->page($jobs, $size, $page, total: count($jobs));
        });
    }

    /**
     * `false` when the seeded run already completed (GitHub's `409`), `true` otherwise. The
     * seeds stay as they are — GitHub cancels asynchronously, so a test seeds the state the
     * next poll reads with {@see seedWorkflowRun()}.
     */
    public function cancelWorkflowRun(string $path, string $runId): bool
    {
        $this->ensureSupported(Feature::CancelWorkflowRun);
        $this->guardAuthenticated();

        $this->record('cancelWorkflowRun', [$path, $runId]);

        return ! ($this->seededRun($runId)?->isCompleted() ?? false);
    }

    /** @return list<WorkflowRun> */
    private function workflowRunList(): array
    {
        /** @var list<WorkflowRun> $runs */
        $runs = $this->seeds->values['workflowRuns'] ?? [];

        return $runs;
    }

    private function seededRun(string $id): ?WorkflowRun
    {
        foreach ($this->workflowRunList() as $run) {
            if ($run->id === $id) {
                return $run;
            }
        }

        return null;
    }

    /** @param list<WorkflowRun> $runs */
    private function firstRunOf(array $runs, string $workflow): ?WorkflowRun
    {
        foreach ($runs as $run) {
            if ($this->runOfWorkflow($run, $workflow)) {
                return $run;
            }
        }

        return null;
    }

    /** Whether a run belongs to a workflow named by file (`deploy.yml`) or by numeric id. */
    private function runOfWorkflow(WorkflowRun $run, string $workflow): bool
    {
        if (ctype_digit($workflow)) {
            return $run->workflowId === $workflow;
        }

        // GitHub may report a reusable workflow's path with its ref: `…/deploy.yml@main`.
        return basename(explode('@', $run->path, 2)[0]) === $workflow;
    }

    /** @param array<string, scalar> $filters */
    private function runMatches(WorkflowRun $run, array $filters): bool
    {
        $after = $filters['createdAfter'] ?? null;
        $before = $filters['createdBefore'] ?? null;
        $status = $filters['status'] ?? null;

        return (! isset($filters['branch']) || $run->headBranch === $filters['branch'])
            && (! isset($filters['event']) || $run->event === $filters['event'])
            && ($status === null || $run->status->value === $status || $run->conclusion?->value === $status)
            && (! isset($filters['actor']) || $run->actor?->name === $filters['actor'])
            && (! isset($filters['headSha']) || $run->headSha === $filters['headSha'])
            && ($after === null || $run->createdAt->getTimestamp() >= Carbon::parse((string) $after)->getTimestamp())
            && ($before === null || $run->createdAt->getTimestamp() <= Carbon::parse((string) $before)->getTimestamp());
    }

    public function createBranch(string $path, NewBranch $data): string
    {
        $this->ensureSupported(Feature::CreateBranch);
        $this->guardAuthenticated();

        $this->record('createBranch', [$path, $data]);

        return "refs/heads/{$data->name}";
    }

    public function createFile(string $path, NewFile $data): Commit
    {
        $this->ensureSupported(Feature::CreateFile);
        $this->guardAuthenticated();

        $this->record('createFile', [$path, $data]);

        return $this->writtenCommit($data->message);
    }

    public function updateFile(string $path, UpdatedFile $data): Commit
    {
        $this->ensureSupported(Feature::UpdateFile);
        $this->guardAuthenticated();

        $this->record('updateFile', [$path, $data]);

        return $this->writtenCommit($data->message);
    }

    public function createPullRequest(string $path, NewPullRequest $data): PullRequest
    {
        $this->ensureSupported(Feature::CreatePullRequest);
        $this->guardAuthenticated();

        $this->record('createPullRequest', [$path, $data]);

        /** @var PullRequest */
        return $this->seeds->values['pullRequest'] ?? new PullRequest(
            provider: $this->name,
            id: 'fake',
            number: 1,
            title: $data->title,
            body: $data->body,
            state: ResourceState::Open,
            sourceBranch: $data->head,
            targetBranch: $data->base,
            author: null,
            url: null,
            createdAt: Carbon::now(),
        );
    }

    /**
     * The seeded pull request, answered as CLOSED.
     *
     * Rebuilt rather than returned as-is: a host that seeded an open pull request and
     * asserts the closed state afterwards is asserting the thing the method exists to do,
     * and a fake that handed the open one back would fail that test for no reason.
     */
    public function closePullRequest(string $path, int $number): PullRequest
    {
        $this->ensureSupported(Feature::ClosePullRequest);
        $this->guardAuthenticated();

        $this->record('closePullRequest', [$path, $number]);

        $pullRequest = $this->seededPullRequest($number);

        return new PullRequest(
            provider: $pullRequest->provider,
            id: $pullRequest->id,
            number: $pullRequest->number,
            title: $pullRequest->title,
            body: $pullRequest->body,
            state: ResourceState::Closed,
            sourceBranch: $pullRequest->sourceBranch,
            targetBranch: $pullRequest->targetBranch,
            author: $pullRequest->author,
            url: $pullRequest->url,
            createdAt: $pullRequest->createdAt,
            draft: $pullRequest->draft,
            raw: $pullRequest->raw,
            headSha: $pullRequest->headSha,
            headRepository: $pullRequest->headRepository,
        );
    }

    public function approvePullRequest(string $path, int $number, ?string $body = null): string
    {
        $this->ensureSupported(Feature::ApprovePullRequest);
        $this->guardAuthenticated();

        $this->record('approvePullRequest', [$path, $number, $body]);

        /** @var string */
        return $this->seeds->values['approvalState'] ?? 'APPROVED';
    }

    /**
     * The review, synthesized from the input — a write, so it never throws.
     *
     * The state is derived from the event the caller submitted rather than seeded,
     * because that mapping is the one thing a host asserting on this call is checking:
     * a fake that answered `APPROVED` for a `COMMENT` review would pass a test of the
     * exact confusion this whole verdict-in-the-body design exists to avoid.
     */
    public function reviewPullRequest(string $path, int $number, NewReview $data): PullRequestReview
    {
        $this->ensureSupported(Feature::ReviewPullRequest);
        $this->guardAuthenticated();

        $this->record('reviewPullRequest', [$path, $number, $data]);

        /** @var PullRequestReview */
        return $this->seeds->values['pullRequestReview'] ?? new PullRequestReview(
            provider: $this->name,
            id: 'fake-review',
            state: match ($data->event) {
                ReviewEvent::Approve => 'APPROVED',
                ReviewEvent::RequestChanges => 'CHANGES_REQUESTED',
                ReviewEvent::Comment => 'COMMENTED',
            },
            body: $data->body,
            author: new Author(name: 'fake', email: '', avatar: null),
            url: null,
            submittedAt: Carbon::now(),
        );
    }

    public function pullRequestReviews(string $path, int $number, int $perPage = 100, int $maxPages = 5): PullRequestReviews
    {
        $this->ensureSupported(Feature::ListPullRequestReviews);

        $this->pageSize($perPage);

        $this->record('pullRequestReviews', [$path, $number, $perPage, $maxPages]);

        /** @var list<PullRequestReview> $reviews */
        $reviews = $this->list('pullRequestReviews');
        /** @var list<PullRequestReviewComment> $comments */
        $comments = $this->list('pullRequestReviewComments');

        return new PullRequestReviews(reviews: $reviews, comments: $comments);
    }

    public function mergePullRequest(
        string $path,
        int $number,
        MergeMethod $method = MergeMethod::Merge,
        ?string $sha = null,
        ?string $title = null,
        ?string $message = null,
    ): string {
        $this->ensureSupported(Feature::MergePullRequest);
        $this->guardAuthenticated();

        $this->record('mergePullRequest', [$path, $number, $method, $sha, $title, $message]);

        /** @var string */
        return $this->seeds->values['mergeCommit'] ?? 'fake-merge-sha';
    }

    public function comment(string $path, NewComment $data): Comment
    {
        $this->ensureSupported(Feature::CreateComment);
        $this->driverChecks()?->validateComment($data);
        $this->guardAuthenticated();

        $this->record('comment', [$path, $data]);

        /** @var Comment */
        return $this->seeds->values['comment'] ?? new Comment(
            id: 'fake',
            body: $data->body,
            author: new Author(name: 'fake', email: '', avatar: null),
            url: null,
            createdAt: Carbon::now(),
        );
    }

    public function createRelease(string $path, NewRelease $data): Release
    {
        $this->ensureSupported(Feature::CreateRelease);
        $this->guardAuthenticated();

        $this->record('createRelease', [$path, $data]);

        /** @var Release */
        return $this->seeds->values['release'] ?? new Release(
            provider: $this->name,
            id: 'fake',
            tagName: $data->tagName,
            name: $data->name,
            body: $data->body,
            draft: $data->draft,
            prerelease: $data->prerelease,
            url: null,
            createdAt: Carbon::now(),
        );
    }

    public function createTag(string $path, NewTag $data): Tag
    {
        $this->ensureSupported(Feature::CreateTag);
        $this->guardAuthenticated();

        $this->record('createTag', [$path, $data]);

        return new Tag(
            provider: $this->name,
            name: $data->name,
            sha: $data->ref,
            url: null,
        );
    }

    /**
     * A fake clone URL that still carries a fake CREDENTIAL for a refreshable one.
     *
     * It used to return `https://{username}@fake/…` with no secret at all, which meant no
     * consumer test could assert that a clone URL is authenticated — the exact bug the
     * real providers had (an app credential silently producing an empty password). A fake
     * that cannot fail the way production failed is not a useful double.
     */
    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string
    {
        $this->record('cloneUrlForRepository', [$path, $username]);

        if ($credentials instanceof GithubApp) {
            throw InvalidCredentialsException::wrongCredentialType(
                $this->name(),
                GithubAppToken::class,
                $credentials::class,
            );
        }

        $path = PathGuard::encode(PathGuard::repository($path));

        $secret = match (true) {
            $credentials instanceof GithubAppToken => 'ghs_fake',
            $credentials instanceof RefreshableCredentials => 'fake-refreshed',
            default => (string) $credentials->credentials?->getValue(),
        };

        // The real driver's username rule (`token` / `x-access-token` on GitHub, `oauth2`
        // on GitLab, the caller's on Bitbucket), encoded exactly as the real drivers encode
        // it, so a host test sees the URL production would hand to `git`. An empty secret
        // keeps its `:` (`user:@host`), as on the real drivers.
        $user = rawurlencode($this->driverChecks()?->cloneUsername($username, $credentials) ?? $username);

        return "https://{$user}:".rawurlencode($secret)."@fake/{$path}.git";
    }

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        $capabilities = [];

        foreach (Feature::cases() as $feature) {
            $capabilities[$feature->value] = $this->supports($feature);
        }

        return $capabilities;
    }

    public function supportsAll(Feature ...$features): bool
    {
        foreach ($features as $feature) {
            if (! $this->supports($feature)) {
                return false;
            }
        }

        return true;
    }

    public function supportsAny(Feature ...$features): bool
    {
        foreach ($features as $feature) {
            if ($this->supports($feature)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<FeatureInfo> */
    public function featureMatrix(): array
    {
        return array_map(fn (Feature $feature): FeatureInfo => $feature->info($this->supports($feature)), Feature::cases());
    }

    /** @return list<FeatureInfo> */
    public function featureInfo(): array
    {
        return array_map(fn (Feature $feature): FeatureInfo => $feature->info(), $this->features);
    }

    public function batch(): Batch
    {
        return new BatchFake($this, $this->git, $this->seeds->batches);
    }

    public function createWebhook(string $path, NewWebhook $data): Webhook
    {
        $this->ensureSupported(Feature::CreateWebhook);
        $this->driverChecks()?->validateNewWebhook($data);
        $this->guardAuthenticated();

        $this->record('createWebhook', [$path, $data]);

        /** @var Webhook|null $seeded */
        $seeded = $this->seeds->values['createWebhook'] ?? null;

        return $seeded ?? new Webhook(
            provider: $this->name,
            id: 'fake',
            url: $data->url,
            events: $data->events,
            active: $data->active,
        );
    }

    /**
     * @internal the real driver's reading of these events, so `register()` matches
     *           seeded hooks exactly as it matches listed ones.
     *
     * @param  list<string>  $events
     * @return list<string>
     */
    public function listedWebhookEvents(array $events): array
    {
        return $this->driverChecks()?->listedWebhookEvents($events) ?? array_values(array_unique($events));
    }

    public function deleteWebhook(string $path, string $id): void
    {
        $this->ensureSupported(Feature::DeleteWebhook);
        $this->guardAuthenticated();

        $this->record('deleteWebhook', [$path, $id]);
    }

    /** @return list<Webhook> */
    public function listWebhooks(string $path): array
    {
        $this->ensureSupported(Feature::ListWebhooks);
        $this->guardAuthenticated();

        $this->record('listWebhooks', [$path]);

        /** @var list<Webhook> $hooks */
        $hooks = $this->seeds->values['webhooks'] ?? [];

        return $hooks;
    }

    /** What the real driver throws for a call that needs a credential and has none. */
    private function guardAuthenticated(): void
    {
        if (! $this->isAuthenticated()) {
            throw InvalidCredentialsException::missing($this->name());
        }
    }

    /**
     * What the real driver throws for the wrong TYPE of credential — an app lookup with an
     * installation token, say.
     *
     * @param  class-string<Credentials>  $required
     */
    private function guardCredential(string $required): void
    {
        $this->guardAuthenticated();

        if (! $this->authentication instanceof $required) {
            throw InvalidCredentialsException::wrongCredentialType(
                $this->name(),
                $required,
                $this->authentication === null ? null : $this->authentication::class,
            );
        }
    }

    /** The real driver, for its input checks — none when a host bound a driver of its own shape. */
    private function driverChecks(): ?BaseProvider
    {
        return $this->driver instanceof BaseProvider ? $this->driver : null;
    }

    private function seededPullRequest(?int $number = null): PullRequest
    {
        /** @var PullRequest|null $seeded */
        $seeded = $this->seeds->values['pullRequest'] ?? $this->list('pullRequests')[0] ?? null;

        return $seeded ?? new PullRequest(
            provider: $this->name,
            id: 'fake',
            number: $number ?? 1,
            title: 'fake',
            body: null,
            state: ResourceState::Open,
            sourceBranch: 'feature',
            targetBranch: 'main',
            author: null,
            url: null,
            createdAt: Carbon::now(),
        );
    }

    private function writtenCommit(string $message): Commit
    {
        /** @var Commit */
        return $this->seeds->values['commit'] ?? new Commit(
            provider: $this->name,
            sha: 'fake-sha',
            message: $message,
            author: new Author(name: 'fake', email: '', avatar: null),
            url: null,
            commitAt: Carbon::now(),
        );
    }

    private function seed(string $key, mixed $value): self
    {
        $this->seeds->values[$key] = $value;

        return $this;
    }

    /**
     * A seeded list bucket, or an empty one.
     *
     * @return list<mixed>
     */
    private function list(string $key): array
    {
        /** @var list<mixed> $items */
        $items = $this->seeds->values[$key] ?? [];

        return $items;
    }

    private function required(string $key, string $seeder): mixed
    {
        return $this->seeds->values[$key] ?? throw $this->unseeded($key, $seeder);
    }

    /** Names the seeder to call, so a missing seed reads as a missing seed. */
    private function unseeded(string $key, string $seeder): RuntimeException
    {
        return new RuntimeException(
            "No {$key} seeded on the [{$this->name()}] fake. Call {$seeder} before the code under test runs."
        );
    }

    /**
     * The page size the real drivers would send: below 1 refused, above 100 capped.
     *
     * @throws InvalidArgumentException when below 1
     */
    private function pageSize(int $perPage): int
    {
        return BaseProvider::boundedPageSize($perPage);
    }

    /**
     * One page of a seeded list — sliced as the forge would page it, with `hasMore` true
     * while seeded items remain past it.
     *
     * @template T
     *
     * @param  list<T>  $items
     * @return Page<T>
     */
    private function page(array $items, int $perPage, int $page = 1, ?int $total = null): Page
    {
        return new Page(
            items: array_slice($items, max(0, $page - 1) * $perPage, $perPage),
            perPage: $perPage,
            page: $page,
            hasMore: count($items) > $page * $perPage,
            total: $total,
        );
    }

    /** @param list<mixed> $arguments */
    private function record(string $method, array $arguments): void
    {
        $this->git->record($this->name, new RecordedCall($method, $arguments));
    }
}
