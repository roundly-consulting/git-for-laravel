<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Providers;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Comparison;
use RoundlyConsulting\Git\Dto\ComparisonFile;
use RoundlyConsulting\Git\Dto\Contributor;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Credentials\GithubApp;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Credentials\OauthToken;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\FileContent;
use RoundlyConsulting\Git\Dto\Input\InstallationTokenScope;
use RoundlyConsulting\Git\Dto\Input\NewBranch;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Dto\Input\NewFile;
use RoundlyConsulting\Git\Dto\Input\NewPullRequest;
use RoundlyConsulting\Git\Dto\Input\NewRelease;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Input\NewReview;
use RoundlyConsulting\Git\Dto\Input\NewReviewComment;
use RoundlyConsulting\Git\Dto\Input\NewTag;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Input\UpdatedFile;
use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Dto\Issue;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\PullRequestReview;
use RoundlyConsulting\Git\Dto\PullRequestReviewComment;
use RoundlyConsulting\Git\Dto\PullRequestReviews;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\MergeMethod;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Handles\PathGuard;
use RoundlyConsulting\Git\Mapping\GithubMapper;
use RoundlyConsulting\Git\Mapping\ResourceMapper;
use RoundlyConsulting\Git\Query\CommitQuery;

class Github extends BaseProvider
{
    protected function key(): string
    {
        return 'github';
    }

    /** `/repos/{owner}/{name}`, the path guarded and encoded segment by segment. */
    protected function repos(string $path): string
    {
        return '/repos/'.$this->repositorySegments($path);
    }

    /**
     * Narrowed to the concrete GitHub mapper (a legal covariant return), so GitHub-only
     * mappings like `installation()` are type-safe without widening the shared
     * ResourceMapper contract with a concept the other two providers do not have.
     */
    protected function mapper(): GithubMapper
    {
        return resolve(GithubMapper::class);
    }

    public function user(): Owner
    {
        $user = $this->get('/user')->json();

        return new Owner(
            id: (string) $user['id'],
            name: $user['login'],
            avatar: $user['avatar_url'] ?? null,
            raw: $user,
        );
    }

    /** @return Page<Repository> */
    public function repositories(int $perPage = 30): Page
    {
        return $this->paginate(
            url: '/user/repos',
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->mapper()->repository($repository),
        );
    }

    /** @return LazyCollection<int, Repository> */
    public function allRepositories(int $perPage = 30): LazyCollection
    {
        return $this->lazyPages(fn (int $page): Page => $this->paginate(
            url: '/user/repos',
            query: [],
            page: $page,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->mapper()->repository($repository),
        ));
    }

    public function repository(string $path): Repository
    {
        return $this->mapper()->repository($this->get($this->repos($path))->json());
    }

    /**
     * The repositories THIS INSTALLATION can reach.
     *
     * Not `/user/repos`: an installation token has no user behind it, so the user
     * endpoints answer 403. This one is also enveloped
     * (`{total_count, repositories: []}`) rather than a bare list.
     *
     * @return Page<Repository>
     */
    public function installationRepositories(int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListInstallationRepositories);
        $this->guardCredential(GithubAppToken::class);

        return $this->paginate(
            url: '/installation/repositories',
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->mapper()->repository($repository),
            itemsKey: 'repositories',
        );
    }

    /** @return LazyCollection<int, Repository> */
    public function allInstallationRepositories(int $perPage = 30): LazyCollection
    {
        $this->guardSupported(Feature::ListInstallationRepositories);
        $this->guardCredential(GithubAppToken::class);

        return $this->lazyPages(fn (int $page): Page => $this->paginate(
            url: '/installation/repositories',
            query: [],
            page: $page,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->mapper()->repository($repository),
            itemsKey: 'repositories',
        ));
    }

    /**
     * Look an installation up as the APP.
     *
     * Authenticate with {@see GithubApp} credentials, not an installation token — this
     * is the call that lets a service verify an installation id somebody handed it
     * before binding anything to it.
     */
    public function installation(string $id): Installation
    {
        $this->guardSupported(Feature::FindInstallation);
        $this->guardCredential(GithubApp::class);

        return $this->mapper()->installation($this->get('/app/installations/'.PathGuard::numeric('installation id', $id))->json());
    }

    /**
     * Every installation of this app (app JWT).
     *
     * @return Page<Installation>
     */
    public function listInstallations(int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListInstallations);
        $this->guardCredential(GithubApp::class);

        return $this->paginate(
            url: '/app/installations',
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $installation): Installation => $this->mapper()->installation($installation),
        );
    }

    /** This app's installation on an organization, if any (app JWT). */
    public function organizationInstallation(string $organization): Installation
    {
        $this->guardSupported(Feature::FindInstallation);
        $this->guardCredential(GithubApp::class);

        return $this->mapper()->installation($this->get('/orgs/'.$this->accountSegment('organization', $organization).'/installation')->json());
    }

    /** This app's installation on a user account, if any (app JWT). */
    public function userInstallation(string $login): Installation
    {
        $this->guardSupported(Feature::FindInstallation);
        $this->guardCredential(GithubApp::class);

        return $this->mapper()->installation($this->get('/users/'.$this->accountSegment('login', $login).'/installation')->json());
    }

    /**
     * Where to send a human to install this app.
     *
     * GitHub echoes `state` back to the app's Setup URL alongside `installation_id`,
     * which is what lets the redirect that comes back be tied to the request that left.
     * Built here rather than in a consumer so nobody hand-composes a github.com URL —
     * and so a GitHub Enterprise host follows the configured API URL (`/api/v3` on the
     * web host for Enterprise Server, an `api.` host for github.com and GHE.com).
     *
     * @throws InvalidCredentialsException when no app slug is configured
     */
    public function installUrl(?string $state = null): string
    {
        $slug = config("git.providers.{$this->key()}.app.slug");

        if (! is_string($slug) || $slug === '') {
            throw InvalidCredentialsException::missingAppConfig($this->key(), 'slug');
        }

        // GitHub Enterprise Server serves app pages under `/github-apps/`, github.com and
        // GHE.com under `/apps/`.
        $apps = $this->isEnterpriseServer() ? 'github-apps' : 'apps';
        $url = $this->webUrl()."/{$apps}/".rawurlencode($slug).'/installations/new';

        return $state === null || $state === '' ? $url : $url.'?state='.urlencode($state);
    }

    /** @return Page<string> */
    public function branches(string $path, int $perPage = 30): Page
    {
        return $this->paginate(
            url: $this->repos($path).'/branches',
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $item): string => $item['name'],
        );
    }

    public function commits(string $path): CommitQuery
    {
        return new CommitQuery(function (array $filters, int $page, int $perPage) use ($path): Page {
            $query = [];

            if (isset($filters['branch'])) {
                $query['sha'] = $filters['branch'];
            }

            if (isset($filters['author'])) {
                $query['author'] = $filters['author'];
            }

            if (isset($filters['path'])) {
                $query['path'] = $filters['path'];
            }

            if (isset($filters['since'])) {
                $query['since'] = $filters['since'];
            }

            if (isset($filters['until'])) {
                $query['until'] = $filters['until'];
            }

            return $this->paginate(
                url: $this->repos($path).'/commits',
                query: $query,
                page: $page,
                perPage: $perPage,
                map: fn (array $commit): Commit => $this->mapper()->commit($commit),
            );
        });
    }

    public function commit(string $path, string $commit): Commit
    {
        return $this->mapper()->commit($this->get($this->repos($path).'/commits/'.$this->refSegments('commit ref', $commit))->json());
    }

    /** @return Page<PullRequest> */
    public function pullRequests(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListPullRequests);

        return $this->paginate(
            url: $this->repos($path).'/pulls',
            query: ['state' => $state],
            page: 1,
            perPage: $perPage,
            map: fn (array $pr): PullRequest => $this->mapper()->pullRequest($pr),
        );
    }

    public function pullRequest(string $path, int $number): PullRequest
    {
        $this->guardSupported(Feature::FindPullRequest);

        return $this->mapper()->pullRequest($this->get($this->repos($path)."/pulls/{$number}")->json());
    }

    /** @return Page<Issue> */
    public function issues(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListIssues);

        return $this->paginate(
            url: $this->repos($path).'/issues',
            query: ['state' => $state],
            page: 1,
            perPage: $perPage,
            map: fn (array $issue): Issue => $this->mapper()->issue($issue),
        );
    }

    public function issue(string $path, int $number): Issue
    {
        $this->guardSupported(Feature::FindIssue);

        return $this->mapper()->issue($this->get($this->repos($path)."/issues/{$number}")->json());
    }

    /** @return Page<Tag> */
    public function tags(string $path, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListTags);

        return $this->paginate(
            url: $this->repos($path).'/tags',
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $tag): Tag => $this->mapper()->tag($tag),
        );
    }

    /** @return Page<Release> */
    public function releases(string $path, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListReleases);

        return $this->paginate(
            url: $this->repos($path).'/releases',
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $release): Release => $this->mapper()->release($release),
        );
    }

    public function release(string $path, string $tagOrId): Release
    {
        $this->guardSupported(Feature::FindRelease);

        return $this->mapper()->release($this->get($this->repos($path).'/releases/tags/'.$this->refSegments('release tag', $tagOrId))->json());
    }

    public function contents(string $path, string $filePath, ?string $ref = null): FileContent
    {
        $this->guardSupported(Feature::FileContents);

        $response = $this->get($this->repos($path).'/contents/'.$this->fileSegments($filePath), $ref !== null ? ['ref' => $ref] : []);

        return $this->mapFileContent($response->json());
    }

    /** @internal the batch plumbing. */
    public function repositoryUrl(string $path): string
    {
        return $this->repos($path);
    }

    /** @internal the batch plumbing. */
    public function languagesUrl(string $path): string
    {
        return $this->repos($path).'/languages';
    }

    /** @internal the batch plumbing. */
    public function pullRequestUrl(string $path, int $number): string
    {
        return $this->repos($path)."/pulls/{$number}";
    }

    /**
     * @internal the batch plumbing.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function contentsRequest(string $path, string $filePath, ?string $ref = null): array
    {
        return [$this->repos($path).'/contents/'.$this->fileSegments($filePath), $ref !== null ? ['ref' => $ref] : []];
    }

    /**
     * @internal the batch plumbing.
     *
     * @param  array<string, mixed>  $raw
     */
    public function mapFileContent(array $raw): FileContent
    {
        return new FileContent(
            path: $raw['path'],
            content: (string) base64_decode((string) ($raw['content'] ?? ''), true),
            sha: $raw['sha'] ?? null,
            size: (int) ($raw['size'] ?? 0),
            url: $raw['html_url'] ?? null,
            raw: $raw,
        );
    }

    public function compare(string $path, string $base, string $head): Comparison
    {
        $this->guardSupported(Feature::Compare);

        $data = $this->get($this->repos($path).'/compare/'.$this->refSegments('base ref', $base).'...'.$this->refSegments('head ref', $head))->json();

        /** @var list<ComparisonFile> $files */
        $files = array_map(fn (array $file): ComparisonFile => new ComparisonFile(
            filename: $file['filename'],
            status: $file['status'],
            additions: (int) ($file['additions'] ?? 0),
            deletions: (int) ($file['deletions'] ?? 0),
        ), $data['files'] ?? []);

        return new Comparison(
            base: $base,
            head: $head,
            aheadBy: (int) ($data['ahead_by'] ?? 0),
            behindBy: (int) ($data['behind_by'] ?? 0),
            files: $files,
        );
    }

    /** @return Page<Contributor> */
    public function contributors(string $path, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListContributors);

        return $this->paginate(
            url: $this->repos($path).'/contributors',
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $c): Contributor => new Contributor(
                id: (string) $c['id'],
                name: $c['login'],
                avatar: $c['avatar_url'] ?? null,
                contributions: (int) ($c['contributions'] ?? 0),
            ),
        );
    }

    /** @return array<string, int> */
    public function languages(string $path): array
    {
        $this->guardSupported(Feature::Languages);

        /** @var array<string, int> $languages */
        $languages = $this->get($this->repos($path).'/languages')->json();

        return $languages;
    }

    /** @return Page<Repository> */
    public function searchRepositories(string $query, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::SearchRepositories);

        return $this->paginate(
            url: '/search/repositories',
            query: ['q' => $query],
            page: 1,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->mapper()->repository($repository),
            itemsKey: 'items',
        );
    }

    /**
     * Create a repository, by one of three routes chosen from the input.
     *
     * The route matters beyond where the repository lands. `/user/repos` and
     * `/orgs/{org}/repos` create an EMPTY repository unless `auto_init` is set — no commits
     * and therefore no default branch — so a caller that immediately runs
     * `git clone --depth 1 --branch <base>` fails on it. `/generate` always produces a
     * commit, which is why generating from a template is the route worth having.
     */
    public function createRepository(NewRepository $data): Repository
    {
        $this->guardSupported(Feature::CreateRepository);

        if ($data->template !== null) {
            // Guarded as its own feature so a provider without an equivalent fails as
            // "unsupported", not as a 404 from a URL that means something else there.
            $this->guardSupported(Feature::GenerateFromTemplate);
        }

        $this->guardAuthenticated();

        return $this->asPermissionFailure(
            'create a repository'.($data->owner !== null ? " in [{$data->owner}]" : ''),
            fn (): Repository => $this->sendRepositoryCreation($data),
        );
    }

    /**
     * Run a creation call, translating "the app was never granted this" into the
     * package's own credential failure.
     *
     * Without this the caller receives Illuminate's `RequestException`, whose message is
     * GitHub's response body verbatim — "Resource not accessible by integration", which
     * names neither the permission nor the account, and which a consumer would end up
     * pattern-matching on or logging onward.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     *
     * @throws InvalidCredentialsException when the app lacks the permission
     */
    private function asPermissionFailure(string $operation, callable $call): mixed
    {
        try {
            return $call();
        } catch (RequestException $exception) {
            $response = $exception->response;

            // 403 is GitHub's answer for a secondary rate limit as well as for a missing
            // permission. A throttled minute must stay retryable — a consumer that reads
            // InvalidCredentialsException tears the connection down — so anything carrying
            // rate-limit signal falls through as the HTTP failure it already was.
            $rateLimited = $response->header('Retry-After') !== ''
                || $response->header('X-RateLimit-Remaining') === '0';

            if ($response->status() !== 403 || $rateLimited) {
                throw $exception;
            }

            throw InvalidCredentialsException::missingPermission(
                provider: $this->name(),
                permission: 'administration: write',
                operation: $operation,
            );
        }
    }

    private function sendRepositoryCreation(NewRepository $data): Repository
    {
        $response = $data->template !== null
            ? $this->send('POST', $this->repos($data->template).'/generate', [
                // Omitted rather than sent as null: GitHub reads an absent `owner` as
                // "the authenticated account", but an explicit null is a 422.
                ...($data->owner !== null ? ['owner' => $data->owner] : []),
                'name' => $data->name,
                'private' => $data->private,
                'description' => $data->description,
                // The template's other branches are its history, not this repository's.
                'include_all_branches' => false,
            ])
            : $this->send('POST', $data->owner !== null ? '/orgs/'.$this->accountSegment('owner', $data->owner).'/repos' : '/user/repos', [
                'name' => $data->name,
                'private' => $data->private,
                'description' => $data->description,
                'auto_init' => $data->autoInit,
            ]);

        /** @var array<string, mixed> $created */
        $created = $response->json();
        $repository = $this->mapper()->repository($created);

        return $this->withDefaultBranch($repository, $created, $data);
    }

    /**
     * Give the created repository the default branch the caller asked for.
     *
     * GitHub accepts `default_branch` on NONE of the three creation routes — it is an
     * update-only field — and silently drops unknown body members, so sending it would
     * leave the caller believing it had asked for `develop` and cloning `main`. Renaming
     * the initial branch is the only route GitHub offers, and it is the one that makes the
     * branch exist as well as be default. It needs `administration: write`, which is
     * exactly what {@see InstallationTokenScope::administrationOnly()}
     * mints.
     *
     * @param  array<string, mixed>  $created  the creation response body
     */
    private function withDefaultBranch(Repository $repository, array $created, NewRepository $data): Repository
    {
        // Nothing to do when it was not asked for, when the repository has no branch to
        // rename (an empty repository reports none), or when it is already right —
        // renaming a branch to its own name is a 422.
        if ($data->defaultBranch === null
            || $repository->defaultBranch === ''
            || $repository->defaultBranch === $data->defaultBranch) {
            return $repository;
        }

        $this->send('POST', $this->repos($repository->path).'/branches/'.$this->refSegments('branch', $repository->defaultBranch).'/rename', [
            'new_name' => $data->defaultBranch,
        ]);

        // `send()` has already thrown on anything but success, so the rename happened and
        // the creation body is now stale. Re-mapping the corrected body keeps the returned
        // DTO describing the repository that exists, without a second round trip to re-read it.
        $created['default_branch'] = $data->defaultBranch;

        return $this->mapper()->repository($created);
    }

    public function createBranch(string $path, NewBranch $data): string
    {
        $this->guardSupported(Feature::CreateBranch);
        $this->guardAuthenticated();

        $base = $this->get($this->repos($path).'/git/refs/heads/'.$this->refSegments('base ref', $data->fromRef))->json();

        $response = $this->send('POST', $this->repos($path).'/git/refs', [
            'ref' => "refs/heads/{$data->name}",
            'sha' => $base['object']['sha'],
        ]);

        return $response->json('ref');
    }

    public function createFile(string $path, NewFile $data): Commit
    {
        $this->guardSupported(Feature::CreateFile);
        $this->guardAuthenticated();

        $response = $this->send('PUT', $this->repos($path).'/contents/'.$this->fileSegments($data->path), [
            'message' => $data->message,
            'content' => base64_encode($data->content),
            'branch' => $data->branch,
        ]);

        return $this->createCommitFromContentResponse($response->json('commit'));
    }

    public function updateFile(string $path, UpdatedFile $data): Commit
    {
        $this->guardSupported(Feature::UpdateFile);
        $this->guardAuthenticated();

        $response = $this->send('PUT', $this->repos($path).'/contents/'.$this->fileSegments($data->path), [
            'message' => $data->message,
            'content' => base64_encode($data->content),
            'branch' => $data->branch,
            'sha' => $data->sha,
        ]);

        return $this->createCommitFromContentResponse($response->json('commit'));
    }

    public function createPullRequest(string $path, NewPullRequest $data): PullRequest
    {
        $this->guardSupported(Feature::CreatePullRequest);
        $this->guardAuthenticated();

        $response = $this->send('POST', $this->repos($path).'/pulls', [
            'title' => $data->title,
            'head' => $data->head,
            'base' => $data->base,
            'body' => $data->body,
        ]);

        return $this->mapper()->pullRequest($response->json());
    }

    /**
     * Close a pull request without merging it.
     *
     * GitHub has no "close" endpoint — closing is a state update — but this method only
     * ever closes. Taking the state as a parameter would make `closePullRequest(…, 'open')`
     * reopen one, which is a method name that lies about what it does.
     */
    public function closePullRequest(string $path, int $number): PullRequest
    {
        $this->guardSupported(Feature::ClosePullRequest);
        $this->guardAuthenticated();

        $response = $this->send('PATCH', $this->repos($path)."/pulls/{$number}", ['state' => 'closed']);

        return $this->mapper()->pullRequest($response->json());
    }

    /**
     * Approve a pull request, as the authenticated account.
     *
     * A review with `event: APPROVE` — GitHub has no separate approve endpoint. **It
     * refuses an account approving its own pull request** (`422 Can not approve your own
     * pull request`), which matters for an App: every PR the App opened is its own, so a
     * caller that authors PRs cannot also approve them. That is GitHub's rule, not this
     * package's, and it surfaces as the `RequestException` any other refusal does.
     */
    public function approvePullRequest(string $path, int $number, ?string $body = null): string
    {
        $this->guardSupported(Feature::ApprovePullRequest);
        $this->guardAuthenticated();

        $response = $this->send('POST', $this->repos($path)."/pulls/{$number}/reviews", array_filter([
            'event' => 'APPROVE',
            'body' => $body,
        ], fn (?string $value): bool => $value !== null));

        // The review's own state, so a caller can report what happened rather than
        // asserting an outcome it never observed.
        return (string) $response->json('state', 'APPROVED');
    }

    /**
     * Publish a review: a verdict, a summary, and the inline comments.
     *
     * One request. GitHub publishes a review atomically, so posting the comments one at
     * a time would leave half a review on the pull request the moment anything failed
     * partway — and each of those comments would notify every watcher separately.
     *
     * **Two different `422`s live on this endpoint**, and a caller has to tell them
     * apart because only one of them is fixable by re-writing the review:
     *
     * - **the verdict** — GitHub refuses `APPROVE` and `REQUEST_CHANGES` from the
     *   account that opened the pull request. For an App that authors pull requests,
     *   that is every one of them; `COMMENT` is always accepted.
     * - **an anchor** — a comment on a line the diff does not contain. The review was
     *   written against a line nobody changed, and re-anchoring it is the fix.
     *
     * Both arrive as the `RequestException` every other refusal in this package does.
     */
    public function reviewPullRequest(string $path, int $number, NewReview $data): PullRequestReview
    {
        $this->guardSupported(Feature::ReviewPullRequest);
        $this->guardAuthenticated();

        $response = $this->send('POST', $this->repos($path)."/pulls/{$number}/reviews", array_filter([
            'event' => $data->event->wire(),
            'body' => $data->body,
            'comments' => array_map($this->reviewComment(...), $data->comments),
        ], fn (mixed $value): bool => $value !== null && $value !== []));

        return $this->mapper()->pullRequestReview($response->json());
    }

    /**
     * One inline comment, in GitHub's wire shape.
     *
     * `start_line`/`start_side` are sent only for a span. GitHub reads their PRESENCE as
     * "this is a multi-line comment" and answers `422` when `start_line` equals `line`,
     * so a null-safe `start_line` on every comment would break every single-line one.
     *
     * @return array<string, mixed>
     */
    private function reviewComment(NewReviewComment $comment): array
    {
        return array_filter([
            'path' => $comment->path,
            'line' => $comment->line,
            'side' => $comment->side->wire(),
            'body' => $comment->body,
            'start_line' => $comment->startLine,
            // GitHub defaults `start_side` to `side` only when it is absent, never when
            // it is null — and a span that straddles nothing still has to name a side.
            'start_side' => $comment->startLine !== null ? $comment->side->wire() : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Every review on a pull request, and every inline comment.
     *
     * Two endpoints, because GitHub keeps them apart: `/reviews` carries the verdicts
     * and summaries, `/comments` the anchored findings. A caller reading only the first
     * gets "changes requested" with nothing that says why.
     *
     * **Both are walked to the end, up to `$maxPages`.** GitHub serves them ASCENDING and
     * offers no `direction` here, so a single page of a long-lived pull request contains
     * the OLDEST reviews — and a caller looking for "the latest verdict" would read the
     * hundredth-oldest one and act on it. The cap is a bound on a pathological thread, not
     * a page size: at the default it is 500 of each.
     */
    public function pullRequestReviews(string $path, int $number, int $perPage = 100, int $maxPages = 5): PullRequestReviews
    {
        $this->guardSupported(Feature::ListPullRequestReviews);

        return new PullRequestReviews(
            reviews: $this->collectPages(
                $this->repos($path)."/pulls/{$number}/reviews",
                [],
                $perPage,
                $maxPages,
                fn (array $review): PullRequestReview => $this->mapper()->pullRequestReview($review),
            ),
            comments: $this->collectPages(
                $this->repos($path)."/pulls/{$number}/comments",
                [],
                $perPage,
                $maxPages,
                fn (array $comment): PullRequestReviewComment => $this->mapper()->pullRequestReviewComment($comment),
            ),
        );
    }

    /**
     * Merge a pull request. Returns the merge commit sha.
     *
     * **A merge GitHub will not perform THROWS**, like every other refusal in this
     * package: `405` for an unmergeable pull request (unsatisfied branch protection, a
     * required check, a merge method the repository disallows) and `409` for a conflict or
     * for `$sha` no longer matching. A `200` from this endpoint always means it merged, so
     * a `merged: false` return would be a shape GitHub never sends — the caller must
     * inspect the exception's status to tell "the provider said no" from "the call failed".
     *
     * `$sha` is the head commit the caller decided about. Passing it makes the merge
     * conditional: a push landing between the review and the merge answers `409` instead
     * of merging code nobody looked at.
     */
    public function mergePullRequest(
        string $path,
        int $number,
        MergeMethod $method = MergeMethod::Merge,
        ?string $sha = null,
        ?string $title = null,
        ?string $message = null,
    ): string {
        $this->guardSupported(Feature::MergePullRequest);
        $this->guardAuthenticated();

        $response = $this->send('PUT', $this->repos($path)."/pulls/{$number}/merge", array_filter([
            'merge_method' => $method->value,
            'sha' => $sha,
            'commit_title' => $title,
            'commit_message' => $message,
        ], fn (?string $value): bool => $value !== null));

        return (string) $response->json('sha', '');
    }

    public function comment(string $path, NewComment $data): Comment
    {
        $this->guardSupported(Feature::CreateComment);
        $this->guardAuthenticated();

        $response = $this->send('POST', $this->repos($path)."/issues/{$data->number}/comments", [
            'body' => $data->body,
        ]);

        $comment = $response->json();

        return new Comment(
            id: (string) $comment['id'],
            body: $comment['body'],
            author: isset($comment['user']) ? new Author(
                name: $comment['user']['login'],
                email: '',
                avatar: $comment['user']['avatar_url'] ?? null,
            ) : null,
            url: $comment['html_url'] ?? null,
            createdAt: Carbon::parse($comment['created_at']),
        );
    }

    public function createRelease(string $path, NewRelease $data): Release
    {
        $this->guardSupported(Feature::CreateRelease);
        $this->guardAuthenticated();

        $response = $this->send('POST', $this->repos($path).'/releases', [
            'tag_name' => $data->tagName,
            'name' => $data->name,
            'body' => $data->body,
            'draft' => $data->draft,
            'prerelease' => $data->prerelease,
        ]);

        return $this->mapper()->release($response->json());
    }

    /**
     * Create a lightweight tag pointing at `$data->ref` — a branch, a tag or a sha.
     *
     * GitHub's create-ref endpoint takes a commit SHA and nothing else (`sha: "main"` is a
     * 422), so a ref that is not already a full sha is resolved first — the same reason
     * {@see createBranch()} reads its base ref before creating anything.
     */
    public function createTag(string $path, NewTag $data): Tag
    {
        $this->guardSupported(Feature::CreateTag);
        $this->guardAuthenticated();

        $sha = $this->commitSha($path, $data->ref);

        $response = $this->send('POST', $this->repos($path).'/git/refs', [
            'ref' => "refs/tags/{$data->name}",
            'sha' => $sha,
        ]);

        $ref = $response->json();

        return new Tag(
            provider: $this->providerName(),
            name: $data->name,
            sha: $ref['object']['sha'] ?? $sha,
            url: $ref['url'] ?? null,
            raw: $ref,
        );
    }

    /** The commit a ref names — without a round trip when it already is a full sha. */
    private function commitSha(string $path, string $ref): string
    {
        if (preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/i', $ref) === 1) {
            return $ref;
        }

        return (string) $this->get($this->repos($path).'/commits/'.$this->refSegments('tag ref', $ref))->json('sha');
    }

    public function createWebhook(string $path, NewWebhook $data): Webhook
    {
        $this->guardSupported(Feature::CreateWebhook);
        $this->guardAuthenticated();

        $response = $this->send('POST', $this->repos($path).'/hooks', [
            'config' => array_filter([
                'url' => $data->url,
                'content_type' => 'json',
                'secret' => $data->secret,
            ], fn ($value): bool => $value !== null),
            'events' => $data->events,
            'active' => $data->active,
        ]);

        $hook = $response->json();

        return new Webhook(
            provider: $this->providerName(),
            id: (string) $hook['id'],
            url: $hook['config']['url'] ?? $data->url,
            events: $hook['events'] ?? $data->events,
            active: (bool) ($hook['active'] ?? $data->active),
            raw: $hook,
        );
    }

    public function deleteWebhook(string $path, string $id): void
    {
        $this->guardSupported(Feature::DeleteWebhook);
        $this->guardAuthenticated();

        $this->send('DELETE', $this->repos($path).'/hooks/'.$this->webhookSegment($id));
    }

    /** @return list<Webhook> */
    public function listWebhooks(string $path): array
    {
        $this->guardSupported(Feature::ListWebhooks);
        $this->guardAuthenticated();

        /** @var list<array<string, mixed>> $hooks */
        $hooks = $this->get($this->repos($path).'/hooks')->json();

        return array_map(fn (array $hook): Webhook => new Webhook(
            provider: $this->providerName(),
            id: (string) $hook['id'],
            url: $hook['config']['url'] ?? '',
            events: $hook['events'] ?? [],
            active: (bool) ($hook['active'] ?? true),
            raw: $hook,
        ), $hooks);
    }

    /**
     * An authenticated HTTPS clone URL.
     *
     * `x-access-token` is GitHub's documented username for an installation token; a PAT
     * keeps the historical `token` username, byte for byte. The secret comes from
     * {@see BaseProvider::cloneSecretFor()}, which is where the two ways this can go
     * wrong quietly are handled.
     *
     * NOTE this is a NETWORK CALL for an installation credential: it mints a token (or
     * reads a cached one), so a loop over N repositories is N mints on a cold cache.
     *
     * @throws InvalidCredentialsException when the credential cannot authenticate a clone
     * @throws RequestException when minting fails upstream
     */
    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string
    {
        return $this->buildCloneUrl(
            baseUrl: $this->cloneBaseUrl(),
            user: $credentials instanceof GithubAppToken ? 'x-access-token' : 'token',
            secret: $this->cloneSecretFor($credentials),
            path: $path,
        );
    }

    protected function cloneBaseUrl(): string
    {
        return $this->webUrl();
    }

    /** @return list<Feature> */
    public function features(): array
    {
        return [
            Feature::ListRepositories,
            Feature::FindRepository,
            Feature::ListCommits,
            Feature::FindCommit,
            Feature::ListRepositoryBranches,
            Feature::ListPullRequests,
            Feature::FindPullRequest,
            Feature::ListIssues,
            Feature::FindIssue,
            Feature::ListTags,
            Feature::ListReleases,
            Feature::FindRelease,
            Feature::FileContents,
            Feature::Compare,
            Feature::ListContributors,
            Feature::Languages,
            Feature::SearchRepositories,
            Feature::CreateRepository,
            Feature::GenerateFromTemplate,
            Feature::CreateBranch,
            Feature::CreateFile,
            Feature::UpdateFile,
            Feature::CreatePullRequest,
            Feature::ClosePullRequest,
            Feature::ApprovePullRequest,
            Feature::ReviewPullRequest,
            Feature::ListPullRequestReviews,
            Feature::MergePullRequest,
            Feature::CreateComment,
            Feature::CreateRelease,
            Feature::CreateTag,
            Feature::CreateWebhook,
            Feature::DeleteWebhook,
            Feature::ListWebhooks,
            Feature::FindInstallation,
            Feature::ListInstallations,
            Feature::ListInstallationRepositories,
        ];
    }

    /** @return list<class-string<Credentials>> */
    public function authenticationMethods(): array
    {
        return [
            Token::class,
            GithubAppToken::class,
            GithubApp::class,
            OauthToken::class,
        ];
    }

    /** @param array<string, mixed> $commit */
    protected function createCommitFromContentResponse(array $commit): Commit
    {
        return new Commit(
            provider: $this->providerName(),
            sha: $commit['sha'],
            message: $commit['message'],
            author: new Author(
                name: $commit['author']['name'] ?? '',
                email: $commit['author']['email'] ?? '',
                avatar: null,
            ),
            url: $commit['html_url'] ?? null,
            commitAt: Carbon::parse($commit['author']['date'] ?? 'now'),
            raw: $commit,
        );
    }
}
