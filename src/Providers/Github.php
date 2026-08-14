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
use RoundlyConsulting\Git\Dto\Input\NewBranch;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Dto\Input\NewFile;
use RoundlyConsulting\Git\Dto\Input\NewPullRequest;
use RoundlyConsulting\Git\Dto\Input\NewRelease;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Input\NewTag;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Input\UpdatedFile;
use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Dto\Issue;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Mapping\GithubMapper;
use RoundlyConsulting\Git\Mapping\ResourceMapper;
use RoundlyConsulting\Git\Query\CommitQuery;

class Github extends BaseProvider
{
    protected function key(): string
    {
        return 'github';
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
        return $this->mapper()->repository($this->get("/repos/{$path}")->json());
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

        return $this->mapper()->installation($this->get("/app/installations/{$id}")->json());
    }

    /**
     * Every installation of this app (app JWT).
     *
     * @return Page<Installation>
     */
    public function installations(int $perPage = 30): Page
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

        return $this->mapper()->installation($this->get("/orgs/{$organization}/installation")->json());
    }

    /** This app's installation on a user account, if any (app JWT). */
    public function userInstallation(string $login): Installation
    {
        $this->guardSupported(Feature::FindInstallation);
        $this->guardCredential(GithubApp::class);

        return $this->mapper()->installation($this->get("/users/{$login}/installation")->json());
    }

    /**
     * Where to send a human to install this app.
     *
     * GitHub echoes `state` back to the app's Setup URL alongside `installation_id`,
     * which is what lets the redirect that comes back be tied to the request that left.
     * Built here rather than in a consumer so nobody hand-composes a github.com URL —
     * and so a GitHub Enterprise host follows the configured API URL.
     *
     * @throws InvalidCredentialsException when no app slug is configured
     */
    public function installUrl(?string $state = null): string
    {
        $slug = config("git.providers.{$this->key()}.app.slug");

        if (! is_string($slug) || $slug === '') {
            throw InvalidCredentialsException::missingAppConfig($this->key(), 'slug');
        }

        $url = rtrim($this->cloneBaseUrl(), '/')."/apps/{$slug}/installations/new";

        return $state === null || $state === '' ? $url : $url.'?state='.urlencode($state);
    }

    /** @return Page<string> */
    public function branches(string $path, int $perPage = 30): Page
    {
        return $this->paginate(
            url: "/repos/{$path}/branches",
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
                url: "/repos/{$path}/commits",
                query: $query,
                page: $page,
                perPage: $perPage,
                map: fn (array $commit): Commit => $this->mapper()->commit($commit),
            );
        });
    }

    public function commit(string $path, string $commit): Commit
    {
        return $this->mapper()->commit($this->get("/repos/{$path}/commits/{$commit}")->json());
    }

    /** @return Page<PullRequest> */
    public function pullRequests(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListPullRequests);

        return $this->paginate(
            url: "/repos/{$path}/pulls",
            query: ['state' => $state],
            page: 1,
            perPage: $perPage,
            map: fn (array $pr): PullRequest => $this->mapper()->pullRequest($pr),
        );
    }

    public function pullRequest(string $path, int $number): PullRequest
    {
        $this->guardSupported(Feature::FindPullRequest);

        return $this->mapper()->pullRequest($this->get("/repos/{$path}/pulls/{$number}")->json());
    }

    /** @return Page<Issue> */
    public function issues(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListIssues);

        return $this->paginate(
            url: "/repos/{$path}/issues",
            query: ['state' => $state],
            page: 1,
            perPage: $perPage,
            map: fn (array $issue): Issue => $this->mapper()->issue($issue),
        );
    }

    public function issue(string $path, int $number): Issue
    {
        $this->guardSupported(Feature::FindIssue);

        return $this->mapper()->issue($this->get("/repos/{$path}/issues/{$number}")->json());
    }

    /** @return Page<Tag> */
    public function tags(string $path, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListTags);

        return $this->paginate(
            url: "/repos/{$path}/tags",
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
            url: "/repos/{$path}/releases",
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $release): Release => $this->mapper()->release($release),
        );
    }

    public function release(string $path, string $tagOrId): Release
    {
        $this->guardSupported(Feature::FindRelease);

        return $this->mapper()->release($this->get("/repos/{$path}/releases/tags/{$tagOrId}")->json());
    }

    public function contents(string $path, string $filePath, ?string $ref = null): FileContent
    {
        $this->guardSupported(Feature::FileContents);

        $response = $this->get("/repos/{$path}/contents/{$filePath}", $ref !== null ? ['ref' => $ref] : []);

        return $this->mapFileContent($response->json());
    }

    public function repositoryUrl(string $path): string
    {
        return "/repos/{$path}";
    }

    public function languagesUrl(string $path): string
    {
        return "/repos/{$path}/languages";
    }

    public function pullRequestUrl(string $path, int $number): string
    {
        return "/repos/{$path}/pulls/{$number}";
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function contentsRequest(string $path, string $filePath, ?string $ref = null): array
    {
        return ["/repos/{$path}/contents/{$filePath}", $ref !== null ? ['ref' => $ref] : []];
    }

    /** @param array<string, mixed> $raw */
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

        $data = $this->get("/repos/{$path}/compare/{$base}...{$head}")->json();

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
            url: "/repos/{$path}/contributors",
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
        $languages = $this->get("/repos/{$path}/languages")->json();

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

    public function createRepository(NewRepository $data): Repository
    {
        $this->guardSupported(Feature::CreateRepository);
        $this->guardAuthenticated();

        $response = $this->send('POST', '/user/repos', [
            'name' => $data->name,
            'private' => $data->private,
            'description' => $data->description,
        ]);

        return $this->mapper()->repository($response->json());
    }

    public function createBranch(string $path, NewBranch $data): string
    {
        $this->guardSupported(Feature::CreateBranch);
        $this->guardAuthenticated();

        $base = $this->get("/repos/{$path}/git/refs/heads/{$data->fromRef}")->json();

        $response = $this->send('POST', "/repos/{$path}/git/refs", [
            'ref' => "refs/heads/{$data->name}",
            'sha' => $base['object']['sha'],
        ]);

        return $response->json('ref');
    }

    public function createFile(string $path, NewFile $data): Commit
    {
        $this->guardSupported(Feature::CreateFile);
        $this->guardAuthenticated();

        $response = $this->send('PUT', "/repos/{$path}/contents/{$data->path}", [
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

        $response = $this->send('PUT', "/repos/{$path}/contents/{$data->path}", [
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

        $response = $this->send('POST', "/repos/{$path}/pulls", [
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
     * GitHub has no "close" endpoint: closing IS a state update, and the same PATCH is
     * what would reopen it — so the state is passed explicitly rather than implied by the
     * method name.
     */
    public function closePullRequest(string $path, int $number, string $state = 'closed'): PullRequest
    {
        $this->guardSupported(Feature::ClosePullRequest);
        $this->guardAuthenticated();

        $response = $this->send('PATCH', "/repos/{$path}/pulls/{$number}", ['state' => $state]);

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
    public function approvePullRequest(string $path, int $number, ?string $body = null): void
    {
        $this->guardSupported(Feature::ApprovePullRequest);
        $this->guardAuthenticated();

        $this->send('POST', "/repos/{$path}/pulls/{$number}/reviews", array_filter([
            'event' => 'APPROVE',
            'body' => $body,
        ], fn (?string $value): bool => $value !== null));
    }

    /**
     * Merge a pull request.
     *
     * `$method` is GitHub's `merge_method` — `merge`, `squash` or `rebase`. A repository
     * that disallows the one asked for answers `405`, and a branch whose protection is
     * unsatisfied answers `405` too: both are the provider's judgement to report, not
     * this package's to pre-empt.
     */
    public function mergePullRequest(
        string $path,
        int $number,
        string $method = 'merge',
        ?string $title = null,
        ?string $message = null,
    ): bool {
        $this->guardSupported(Feature::MergePullRequest);
        $this->guardAuthenticated();

        $response = $this->send('PUT', "/repos/{$path}/pulls/{$number}/merge", array_filter([
            'merge_method' => $method,
            'commit_title' => $title,
            'commit_message' => $message,
        ], fn (?string $value): bool => $value !== null));

        return (bool) $response->json('merged', false);
    }

    public function comment(string $path, NewComment $data): Comment
    {
        $this->guardSupported(Feature::CreateComment);
        $this->guardAuthenticated();

        $response = $this->send('POST', "/repos/{$path}/issues/{$data->number}/comments", [
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

        $response = $this->send('POST', "/repos/{$path}/releases", [
            'tag_name' => $data->tagName,
            'name' => $data->name,
            'body' => $data->body,
            'draft' => $data->draft,
            'prerelease' => $data->prerelease,
        ]);

        return $this->mapper()->release($response->json());
    }

    public function createTag(string $path, NewTag $data): Tag
    {
        $this->guardSupported(Feature::CreateTag);
        $this->guardAuthenticated();

        $response = $this->send('POST', "/repos/{$path}/git/refs", [
            'ref' => "refs/tags/{$data->name}",
            'sha' => $data->ref,
        ]);

        $ref = $response->json();

        return new Tag(
            provider: $this->providerName(),
            name: $data->name,
            sha: $ref['object']['sha'] ?? $data->ref,
            url: $ref['url'] ?? null,
            raw: $ref,
        );
    }

    public function createWebhook(string $path, NewWebhook $data): Webhook
    {
        $this->guardSupported(Feature::CreateWebhook);
        $this->guardAuthenticated();

        $response = $this->send('POST', "/repos/{$path}/hooks", [
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

        $this->send('DELETE', "/repos/{$path}/hooks/{$id}");
    }

    /** @return list<Webhook> */
    public function listWebhooks(string $path): array
    {
        $this->guardSupported(Feature::ListWebhooks);
        $this->guardAuthenticated();

        /** @var list<array<string, mixed>> $hooks */
        $hooks = $this->get("/repos/{$path}/hooks")->json();

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
        return str((string) config('git.providers.github.url'))->replace('api.', '')->toString();
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
            Feature::CreateBranch,
            Feature::CreateFile,
            Feature::UpdateFile,
            Feature::CreatePullRequest,
            Feature::ClosePullRequest,
            Feature::ApprovePullRequest,
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
