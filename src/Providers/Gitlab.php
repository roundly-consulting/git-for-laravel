<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Comparison;
use RoundlyConsulting\Git\Dto\ComparisonFile;
use RoundlyConsulting\Git\Dto\Contributor;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
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
use RoundlyConsulting\Git\Dto\Issue;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\CommentTarget;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Handles\PathGuard;
use RoundlyConsulting\Git\Mapping\GitlabMapper;
use RoundlyConsulting\Git\Mapping\ResourceMapper;
use RoundlyConsulting\Git\Query\CommitQuery;

class Gitlab extends BaseProvider
{
    /** @var array<string, string> canonical event => GitLab's hook flag for it */
    private const WEBHOOK_EVENTS = [
        'push' => 'push_events',
        'pull_request' => 'merge_requests_events',
        'issues' => 'issues_events',
    ];

    protected function key(): string
    {
        return 'gitlab';
    }

    protected function mapper(): ResourceMapper
    {
        return resolve(GitlabMapper::class);
    }

    public function user(): Owner
    {
        $user = $this->get('/api/v4/user')->json();

        return new Owner(
            id: (string) $user['id'],
            name: $user['username'],
            avatar: $user['avatar_url'] ?? null,
            raw: $user,
        );
    }

    /** @return Page<Repository> */
    public function repositories(int $perPage = 30): Page
    {
        return $this->paginate(
            url: '/api/v4/projects',
            query: ['membership' => 'true'],
            page: 1,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->mapper()->repository($repository),
        );
    }

    /** @return LazyCollection<int, Repository> */
    public function allRepositories(int $perPage = 30): LazyCollection
    {
        return $this->lazyPages(fn (int $page): Page => $this->paginate(
            url: '/api/v4/projects',
            query: ['membership' => 'true'],
            page: $page,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->mapper()->repository($repository),
        ));
    }

    public function repository(string $path): Repository
    {
        return $this->mapper()->repository(
            $this->get('/api/v4/projects/'.$this->encode($path))->json()
        );
    }

    /** @return Page<string> */
    public function branches(string $path, int $perPage = 30): Page
    {
        return $this->paginate(
            url: '/api/v4/projects/'.$this->encode($path).'/repository/branches',
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
                $query['ref_name'] = $filters['branch'];
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
                url: '/api/v4/projects/'.$this->encode($path).'/repository/commits',
                query: $query,
                page: $page,
                perPage: $perPage,
                map: fn (array $commit): Commit => $this->mapper()->commit($commit),
            );
        });
    }

    public function commit(string $path, string $commit): Commit
    {
        return $this->mapper()->commit(
            $this->get('/api/v4/projects/'.$this->encode($path).'/repository/commits/'.$this->encodeWhole(PathGuard::ref('commit ref', $commit)))->json()
        );
    }

    /** @return Page<PullRequest> */
    public function pullRequests(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListPullRequests);

        return $this->paginate(
            url: '/api/v4/projects/'.$this->encode($path).'/merge_requests',
            query: ['state' => $this->mapState($state)],
            page: 1,
            perPage: $perPage,
            map: fn (array $mr): PullRequest => $this->mapper()->pullRequest($mr),
        );
    }

    public function pullRequest(string $path, int $number): PullRequest
    {
        $this->guardSupported(Feature::FindPullRequest);

        return $this->mapper()->pullRequest(
            $this->get('/api/v4/projects/'.$this->encode($path)."/merge_requests/{$number}")->json()
        );
    }

    /** @return Page<Issue> */
    public function issues(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListIssues);

        return $this->paginate(
            url: '/api/v4/projects/'.$this->encode($path).'/issues',
            query: ['state' => $this->mapState($state)],
            page: 1,
            perPage: $perPage,
            map: fn (array $issue): Issue => $this->mapper()->issue($issue),
        );
    }

    public function issue(string $path, int $number): Issue
    {
        $this->guardSupported(Feature::FindIssue);

        return $this->mapper()->issue(
            $this->get('/api/v4/projects/'.$this->encode($path)."/issues/{$number}")->json()
        );
    }

    /** @return Page<Tag> */
    public function tags(string $path, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListTags);

        return $this->paginate(
            url: '/api/v4/projects/'.$this->encode($path).'/repository/tags',
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
            url: '/api/v4/projects/'.$this->encode($path).'/releases',
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $release): Release => $this->mapper()->release($release),
        );
    }

    public function release(string $path, string $tagOrId): Release
    {
        $this->guardSupported(Feature::FindRelease);

        return $this->mapper()->release(
            $this->get('/api/v4/projects/'.$this->encode($path).'/releases/'.$this->encodeWhole(PathGuard::ref('release tag', $tagOrId)))->json()
        );
    }

    public function contents(string $path, string $filePath, ?string $ref = null): FileContent
    {
        $this->guardSupported(Feature::FileContents);

        [$url, $query] = $this->contentsRequest($path, $filePath, $ref);

        return $this->mapFileContent($this->get($url, $query)->json());
    }

    /** @internal the batch plumbing. */
    public function repositoryUrl(string $path): string
    {
        return '/api/v4/projects/'.$this->encode($path);
    }

    /** @internal the batch plumbing. */
    public function languagesUrl(string $path): string
    {
        return '/api/v4/projects/'.$this->encode($path).'/languages';
    }

    /** @internal the batch plumbing. */
    public function pullRequestUrl(string $path, int $number): string
    {
        return '/api/v4/projects/'.$this->encode($path)."/merge_requests/{$number}";
    }

    /**
     * GitLab requires a `ref` here, so an omitted one is `HEAD` — GitLab's name for the
     * project's default branch — rather than a guess like `main`, which 404s on every
     * project whose default branch is called anything else.
     *
     * @internal the batch plumbing.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function contentsRequest(string $path, string $filePath, ?string $ref = null): array
    {
        return [
            '/api/v4/projects/'.$this->encode($path).'/repository/files/'.$this->encodeWhole(PathGuard::file($filePath)),
            ['ref' => $ref ?? 'HEAD'],
        ];
    }

    /**
     * @internal the batch plumbing.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, int>
     */
    public function normalizeLanguages(array $raw): array
    {
        /** @var array<string, int> $languages */
        $languages = array_map(fn (mixed $percent): int => (int) round((float) $percent), $raw);

        return $languages;
    }

    /**
     * @internal the batch plumbing.
     *
     * @param  array<string, mixed>  $raw
     */
    public function mapFileContent(array $raw): FileContent
    {
        return new FileContent(
            path: $raw['file_path'],
            content: (string) base64_decode((string) ($raw['content'] ?? ''), true),
            sha: $raw['blob_id'] ?? null,
            size: (int) ($raw['size'] ?? 0),
            url: null,
            raw: $raw,
        );
    }

    public function compare(string $path, string $base, string $head): Comparison
    {
        $this->guardSupported(Feature::Compare);

        /** @var array<string, mixed> $data */
        $data = $this->get(
            '/api/v4/projects/'.$this->encode($path).'/repository/compare',
            ['from' => $base, 'to' => $head],
        )->json();

        /** @var list<array<string, mixed>> $commits */
        $commits = array_values(array_filter(is_array($data['commits'] ?? null) ? $data['commits'] : [], is_array(...)));

        /** @var list<ComparisonFile> $files */
        $files = array_map(fn (array $diff): ComparisonFile => new ComparisonFile(
            filename: $diff['new_path'],
            status: ($diff['new_file'] ?? false) ? 'added' : (($diff['deleted_file'] ?? false) ? 'removed' : 'modified'),
            additions: 0,
            deletions: 0,
        ), $data['diffs'] ?? []);

        // No `status`: GitLab cannot tell diverged from ahead, and a forward-only guard must
        // not read a guess. Unpaged, GitLab sends every commit, so the count is the total.
        return new Comparison(
            base: $base,
            head: $head,
            aheadBy: count($commits),
            behindBy: 0,
            files: $files,
            raw: $data,
            status: null,
            totalCommits: count($commits),
            commits: array_map(fn (array $commit): Commit => $this->mapper()->commit($commit), $commits),
        );
    }

    /** @return Page<Contributor> */
    public function contributors(string $path, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListContributors);

        return $this->paginate(
            url: '/api/v4/projects/'.$this->encode($path).'/repository/contributors',
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $c): Contributor => new Contributor(
                id: $c['email'] ?? $c['name'],
                name: $c['name'],
                avatar: null,
                contributions: (int) ($c['commits'] ?? 0),
            ),
        );
    }

    /** @return array<string, int> */
    public function languages(string $path): array
    {
        $this->guardSupported(Feature::Languages);

        return $this->normalizeLanguages($this->get($this->languagesUrl($path))->json());
    }

    /** @return Page<Repository> */
    public function searchRepositories(string $query, int $perPage = 30): Page
    {
        $this->guardSupported(Feature::SearchRepositories);

        return $this->paginate(
            url: '/api/v4/projects',
            query: ['search' => $query],
            page: 1,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->mapper()->repository($repository),
        );
    }

    /**
     * Create a project.
     *
     * `owner` maps to `namespace_id`, which GitLab defines as an INTEGER group or subgroup
     * id — there is no path-string form. A non-numeric owner is therefore refused rather
     * than dropped: a dropped namespace creates the project in the caller's personal
     * namespace, which looks like success and is the wrong place.
     *
     * @throws InvalidArgumentException when the owner is not a numeric namespace id
     */
    public function createRepository(NewRepository $data): Repository
    {
        $this->guardSupported(Feature::CreateRepository);

        if ($data->template !== null) {
            // GitLab's project templates are a different concept from generating a
            // repository out of another repository, so this is genuinely unsupported
            // rather than merely spelled differently.
            $this->guardSupported(Feature::GenerateFromTemplate);
        }

        $this->validateNewRepository($data);
        $this->guardAuthenticated();

        $response = $this->send('POST', '/api/v4/projects', [
            'name' => $data->name,
            'visibility' => $data->private ? 'private' : 'public',
            'description' => $data->description,
            ...($data->owner !== null ? ['namespace_id' => (int) $data->owner] : []),
            'initialize_with_readme' => $data->autoInit,
            // GitLab takes this at creation time (unlike GitHub) but documents it as
            // requiring `initialize_with_readme` — the constraint `NewRepository` already
            // enforces, so anything that arrives here is valid.
            ...($data->defaultBranch !== null ? ['default_branch' => $data->defaultBranch] : []),
        ]);

        return $this->mapper()->repository($response->json());
    }

    /**
     * @internal the drivers' and the fake's shared input checks.
     *
     * @throws InvalidArgumentException when the owner is not a numeric namespace id
     */
    public function validateNewRepository(NewRepository $data): void
    {
        if ($data->owner !== null && ! ctype_digit($data->owner)) {
            throw new InvalidArgumentException(
                "GitLab needs a numeric namespace id for the owner, got [{$data->owner}]."
            );
        }
    }

    /**
     * Create a branch; returns the new ref (`refs/heads/<name>`), as every driver does —
     * GitLab answers with the branch object, whose `name` is the bare branch name.
     */
    public function createBranch(string $path, NewBranch $data): string
    {
        $this->guardSupported(Feature::CreateBranch);
        $this->guardAuthenticated();

        $response = $this->send('POST', '/api/v4/projects/'.$this->encode($path).'/repository/branches', [
            'branch' => $data->name,
            'ref' => $data->fromRef,
        ]);

        return 'refs/heads/'.$response->json('name');
    }

    /**
     * Create a file, as one commit — through the Commits API rather than the Files API.
     *
     * The Files API answers a write with `{file_path, branch}` and nothing else, so the
     * commit it made cannot be returned; the Commits API answers with the commit itself.
     * Content goes base64-encoded, so binary content survives the JSON body.
     */
    public function createFile(string $path, NewFile $data): Commit
    {
        $this->guardSupported(Feature::CreateFile);
        $this->guardAuthenticated();

        return $this->commitFile($path, 'create', $data->path, $data->content, $data->message, $data->branch);
    }

    /**
     * Update a file, as one commit. GitLab has no blob-sha precondition, so the
     * `UpdatedFile::$sha` GitHub checks is not sent.
     */
    public function updateFile(string $path, UpdatedFile $data): Commit
    {
        $this->guardSupported(Feature::UpdateFile);
        $this->guardAuthenticated();

        return $this->commitFile($path, 'update', $data->path, $data->content, $data->message, $data->branch);
    }

    private function commitFile(string $path, string $action, string $filePath, string $content, string $message, string $branch): Commit
    {
        $response = $this->send('POST', '/api/v4/projects/'.$this->encode($path).'/repository/commits', [
            'branch' => $branch,
            'commit_message' => $message,
            'actions' => [[
                'action' => $action,
                'file_path' => PathGuard::file($filePath),
                'content' => base64_encode($content),
                'encoding' => 'base64',
            ]],
        ]);

        return $this->mapper()->commit($response->json());
    }

    public function createPullRequest(string $path, NewPullRequest $data): PullRequest
    {
        $this->guardSupported(Feature::CreatePullRequest);
        $this->guardAuthenticated();

        $response = $this->send('POST', '/api/v4/projects/'.$this->encode($path).'/merge_requests', [
            'title' => $data->title,
            'source_branch' => $data->head,
            'target_branch' => $data->base,
            'description' => $data->body,
        ]);

        return $this->mapper()->pullRequest($response->json());
    }

    /**
     * Comment on an issue or a merge request — which one `$data->target` says.
     *
     * GitLab numbers the two separately, so issue #3 and merge request !3 are different
     * objects and there is nothing to infer the target from; posting to either by default
     * would comment on the wrong object (or 404) half the time.
     *
     * @throws InvalidArgumentException when the comment names no target
     */
    public function comment(string $path, NewComment $data): Comment
    {
        $this->guardSupported(Feature::CreateComment);
        $this->validateComment($data);
        $this->guardAuthenticated();

        $collection = $data->target === CommentTarget::Issue ? 'issues' : 'merge_requests';

        $response = $this->send(
            'POST',
            '/api/v4/projects/'.$this->encode($path)."/{$collection}/{$data->number}/notes",
            ['body' => $data->body],
        );

        $note = $response->json();

        return new Comment(
            id: (string) $note['id'],
            body: $note['body'],
            author: isset($note['author']) ? new Author(
                name: $note['author']['username'],
                email: '',
                avatar: $note['author']['avatar_url'] ?? null,
            ) : null,
            url: null,
            createdAt: Carbon::parse($note['created_at']),
        );
    }

    /**
     * @internal the drivers' and the fake's shared input checks.
     *
     * @throws InvalidArgumentException when the comment names no target
     */
    public function validateComment(NewComment $data): void
    {
        if ($data->target === null) {
            throw new InvalidArgumentException(
                'GitLab numbers issues and merge requests separately: pass target: CommentTarget::Issue or CommentTarget::PullRequest.'
            );
        }
    }

    public function createRelease(string $path, NewRelease $data): Release
    {
        $this->guardSupported(Feature::CreateRelease);
        $this->guardAuthenticated();

        $response = $this->send('POST', '/api/v4/projects/'.$this->encode($path).'/releases', [
            'tag_name' => $data->tagName,
            'name' => $data->name,
            'description' => $data->body,
        ]);

        return $this->mapper()->release($response->json());
    }

    public function createTag(string $path, NewTag $data): Tag
    {
        $this->guardSupported(Feature::CreateTag);
        $this->guardAuthenticated();

        $response = $this->send('POST', '/api/v4/projects/'.$this->encode($path).'/repository/tags', [
            'tag_name' => $data->name,
            'ref' => $data->ref,
        ]);

        $tag = $response->json();

        return new Tag(
            provider: $this->providerName(),
            name: $tag['name'],
            sha: $tag['commit']['id'] ?? $data->ref,
            url: null,
            raw: $tag,
        );
    }

    /**
     * Register a project hook.
     *
     * GitLab subscribes by boolean flag, not by event list: `push` is `push_events`,
     * `pull_request` is `merge_requests_events`, `issues` is `issues_events`, and a native
     * `*_events` flag passes through. `push_events` is always sent — GitLab defaults it to
     * TRUE, so leaving it out would subscribe a pull-request-only hook to every push.
     *
     * @throws InvalidArgumentException for an event GitLab has no flag for
     * @throws FeatureNotSupportedException for an inactive hook — GitLab cannot create one
     */
    public function createWebhook(string $path, NewWebhook $data): Webhook
    {
        $this->guardSupported(Feature::CreateWebhook);
        $this->validateNewWebhook($data);
        $this->guardAuthenticated();

        $response = $this->send('POST', '/api/v4/projects/'.$this->encode($path).'/hooks', [
            'url' => $data->url,
            ...$this->webhookFlags($data->events),
            'token' => $data->secret,
            'enable_ssl_verification' => true,
        ]);

        $hook = $response->json();

        return $this->mapWebhook($hook, $data->url);
    }

    /**
     * @internal the drivers' and the fake's shared input checks.
     *
     * @throws FeatureNotSupportedException for an inactive hook — GitLab cannot create one
     * @throws InvalidArgumentException for an event GitLab has no flag for
     */
    public function validateNewWebhook(NewWebhook $data): void
    {
        if (! $data->active) {
            throw FeatureNotSupportedException::for('inactive webhooks', $this->name());
        }

        $this->webhookFlags($data->events);
    }

    public function deleteWebhook(string $path, string $id): void
    {
        $this->guardSupported(Feature::DeleteWebhook);
        $this->guardAuthenticated();

        $this->send('DELETE', '/api/v4/projects/'.$this->encode($path).'/hooks/'.$this->webhookSegment($id));
    }

    /**
     * Every hook on the project — all pages, not just GitLab's first twenty.
     *
     * @return list<Webhook>
     */
    public function listWebhooks(string $path): array
    {
        $this->guardSupported(Feature::ListWebhooks);
        $this->guardAuthenticated();

        return $this->collectPages(
            '/api/v4/projects/'.$this->encode($path).'/hooks',
            [],
            self::MAX_PER_PAGE,
            self::MAX_WEBHOOK_PAGES,
            fn (array $hook): Webhook => $this->mapWebhook($hook, ''),
        );
    }

    /**
     * @internal how `listWebhooks()` reports these events: a known flag by its canonical
     *           name (`merge_requests_events` → `pull_request`), anything else as given.
     *
     * @param  list<string>  $events
     * @return list<string>
     */
    public function listedWebhookEvents(array $events): array
    {
        $canonical = array_flip(self::WEBHOOK_EVENTS);

        return array_values(array_unique(array_map(
            fn (string $event): string => $canonical[$event] ?? $event,
            $events,
        )));
    }

    /**
     * The event flags for a hook, every known one set explicitly.
     *
     * @param  list<string>  $events
     * @return array<string, bool>
     */
    private function webhookFlags(array $events): array
    {
        $flags = array_fill_keys(array_values(self::WEBHOOK_EVENTS), false);

        foreach ($events as $event) {
            $flag = self::WEBHOOK_EVENTS[$event]
                ?? (str_ends_with($event, '_events') && preg_match('/^[a-z_]+$/', $event) === 1 ? $event : null);

            if ($flag === null) {
                throw new InvalidArgumentException(
                    "GitLab has no webhook flag for the event [{$event}]. Use push, pull_request, issues, or a native `*_events` flag."
                );
            }

            $flags[$flag] = true;
        }

        return $flags;
    }

    /**
     * A hook as the canonical DTO: events read back from its flags, and active from its
     * alert status — GitLab disables a failing hook (`disabled`, `temporarily_disabled`);
     * `enable_ssl_verification` says nothing about whether it fires.
     *
     * @param  array<string, mixed>  $hook
     */
    private function mapWebhook(array $hook, string $fallbackUrl): Webhook
    {
        $canonical = array_flip(self::WEBHOOK_EVENTS);
        $events = [];

        foreach ($hook as $key => $value) {
            if (str_ends_with($key, '_events') && $value === true) {
                $events[] = $canonical[$key] ?? $key;
            }
        }

        return new Webhook(
            provider: $this->providerName(),
            id: (string) $hook['id'],
            url: is_string($hook['url'] ?? null) ? $hook['url'] : $fallbackUrl,
            events: $events,
            active: ($hook['alert_status'] ?? 'executable') === 'executable',
            raw: $hook,
        );
    }

    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string
    {
        return $this->buildCloneUrl(
            baseUrl: $this->cloneBaseUrl(),
            user: $this->cloneUsername($username, $credentials),
            // An OauthToken is refreshable and carries NO static secret, so reading
            // `credentials` produced `https://oauth2:@gitlab.com/...` — silently
            // unauthenticated. Same class of bug as the GitHub App path.
            secret: $this->cloneSecretFor($credentials),
            path: $path,
        );
    }

    /**
     * `oauth2`, GitLab's username for a token of any kind.
     *
     * @internal the drivers' and the fake's shared clone-URL rule.
     */
    public function cloneUsername(string $username, Credentials $credentials): string
    {
        return 'oauth2';
    }

    /** GitLab's API lives under `/api/v4` on the web host, so the configured URL IS the web host. */
    protected function cloneBaseUrl(): string
    {
        return $this->apiUrl();
    }

    protected function hasMorePages(Response $response, int $count, int $perPage): bool
    {
        $next = $response->header('X-Next-Page');

        if ($next !== '') {
            return true;
        }

        // No paging headers (e.g. on faked responses): fall back to a full page.
        if ($response->header('X-Page') === '') {
            return $count >= $perPage;
        }

        return false;
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
            Feature::CreateComment,
            Feature::CreateRelease,
            Feature::CreateTag,
            Feature::CreateWebhook,
            Feature::DeleteWebhook,
            Feature::ListWebhooks,
        ];
    }

    /** @return list<class-string<Credentials>> */
    public function authenticationMethods(): array
    {
        return [
            Token::class,
            OauthToken::class,
        ];
    }

    /**
     * A project path as GitLab addresses it: guarded, then encoded WHOLE — GitLab takes
     * `group/sub/project` as one `%2F`-joined id, not as path segments.
     *
     * @throws OutOfScopeException
     */
    protected function encode(string $path): string
    {
        return $this->encodeWhole(PathGuard::repository($path));
    }

    /** One URL segment for an already-guarded value: file paths and refs are `%2F`-joined too. */
    private function encodeWhole(string $value): string
    {
        return rawurlencode($value);
    }

    protected function mapState(string $state): string
    {
        return match ($state) {
            'open' => 'opened',
            'closed' => 'closed',
            default => $state,
        };
    }
}
