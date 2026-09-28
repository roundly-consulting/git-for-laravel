<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Providers;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Dto\Input\NewPullRequest;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Mapping\BitbucketMapper;
use RoundlyConsulting\Git\Mapping\ResourceMapper;
use RoundlyConsulting\Git\Query\CommitQuery;

class Bitbucket extends BaseProvider
{
    /** @var array<string, list<string>> canonical event => Bitbucket's events for it */
    private const WEBHOOK_EVENTS = [
        'push' => ['repo:push'],
        'pull_request' => ['pullrequest:created', 'pullrequest:updated', 'pullrequest:fulfilled', 'pullrequest:rejected'],
    ];

    protected function key(): string
    {
        return 'bitbucket';
    }

    protected function mapper(): ResourceMapper
    {
        return resolve(BitbucketMapper::class);
    }

    public function user(): Owner
    {
        $user = $this->get('/2.0/user')->json();

        return new Owner(
            id: (string) $user['uuid'],
            name: $user['username'],
            avatar: $user['links']['avatar']['href'] ?? null,
            raw: $user,
        );
    }

    /** @return Page<Repository> */
    public function repositories(int $perPage = 30): Page
    {
        return $this->paginate(
            url: '/2.0/repositories',
            query: ['role' => 'member'],
            page: 1,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->mapper()->repository($repository),
            itemsKey: 'values',
        );
    }

    /** @return LazyCollection<int, Repository> */
    public function allRepositories(int $perPage = 30): LazyCollection
    {
        return $this->lazyPages(fn (int $page): Page => $this->paginate(
            url: '/2.0/repositories',
            query: ['role' => 'member'],
            page: $page,
            perPage: $perPage,
            map: fn (array $repository): Repository => $this->mapper()->repository($repository),
            itemsKey: 'values',
        ));
    }

    public function repository(string $path): Repository
    {
        return $this->mapper()->repository($this->get($this->repositoryUrl($path))->json());
    }

    /** @return Page<string> */
    public function branches(string $path, int $perPage = 30): Page
    {
        return $this->paginate(
            url: $this->repositoryUrl($path).'/refs/branches',
            query: [],
            page: 1,
            perPage: $perPage,
            map: fn (array $item): string => $item['name'],
            itemsKey: 'values',
        );
    }

    public function commits(string $path): CommitQuery
    {
        return new CommitQuery(function (array $filters, int $page, int $perPage) use ($path): Page {
            $query = [];

            if (isset($filters['branch'])) {
                $query['include'] = $filters['branch'];
            }

            if (isset($filters['path'])) {
                $query['path'] = $filters['path'];
            }

            return $this->paginate(
                url: $this->repositoryUrl($path).'/commits',
                query: $query,
                page: $page,
                perPage: $perPage,
                map: fn (array $commit): Commit => $this->mapper()->commit($commit),
                itemsKey: 'values',
            );
        });
    }

    public function commit(string $path, string $commit): Commit
    {
        return $this->mapper()->commit($this->get($this->repositoryUrl($path).'/commit/'.$this->refSegments('commit ref', $commit))->json());
    }

    /** @return Page<PullRequest> */
    public function pullRequests(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListPullRequests);

        return $this->paginate(
            url: $this->repositoryUrl($path).'/pullrequests',
            query: ['state' => $this->mapState($state)],
            page: 1,
            perPage: $perPage,
            map: fn (array $pr): PullRequest => $this->mapper()->pullRequest($pr),
            itemsKey: 'values',
        );
    }

    public function pullRequest(string $path, int $number): PullRequest
    {
        $this->guardSupported(Feature::FindPullRequest);

        return $this->mapper()->pullRequest($this->get($this->repositoryUrl($path)."/pullrequests/{$number}")->json());
    }

    /**
     * `/2.0/repositories/{workspace}/{slug}`, the path guarded and encoded segment by
     * segment — every Bitbucket URL starts here.
     *
     * @internal the batch plumbing.
     */
    public function repositoryUrl(string $path): string
    {
        return '/2.0/repositories/'.$this->repositorySegments($path);
    }

    /** @internal the batch plumbing. */
    public function pullRequestUrl(string $path, int $number): string
    {
        return $this->repositoryUrl($path)."/pullrequests/{$number}";
    }

    /**
     * Create a repository in a workspace.
     *
     * `owner` is the workspace. Bitbucket's route is `/2.0/repositories/{workspace}/{slug}`
     * and always has been; the ownerless form here posts a bare name at it, which is what
     * this endpoint looked like before workspaces and no longer addresses anything. It is
     * kept only so an existing caller's behaviour does not change silently — pass an owner.
     *
     * Bitbucket has no initial-commit option and no template generation, so both are
     * refused rather than dropped.
     */
    public function createRepository(NewRepository $data): Repository
    {
        $this->guardSupported(Feature::CreateRepository);

        if ($data->template !== null) {
            $this->guardSupported(Feature::GenerateFromTemplate);
        }

        // No `auto_init` equivalent exists, so honouring these would mean pretending. A
        // caller that asked for an initial commit and silently got an empty repository
        // discovers it at `git clone`, far from here.
        if ($data->autoInit || $data->defaultBranch !== null) {
            $this->featureNotSupported();
        }

        $this->guardAuthenticated();

        $path = $data->owner !== null ? "{$data->owner}/{$data->name}" : $data->name;

        $response = $this->send('POST', $this->repositoryUrl($path), [
            'scm' => 'git',
            'is_private' => $data->private,
            'description' => $data->description,
        ]);

        return $this->mapper()->repository($response->json());
    }

    public function createPullRequest(string $path, NewPullRequest $data): PullRequest
    {
        $this->guardSupported(Feature::CreatePullRequest);
        $this->guardAuthenticated();

        $response = $this->send('POST', $this->repositoryUrl($path).'/pullrequests', [
            'title' => $data->title,
            'source' => ['branch' => ['name' => $data->head]],
            'destination' => ['branch' => ['name' => $data->base]],
            'description' => $data->body,
        ]);

        return $this->mapper()->pullRequest($response->json());
    }

    public function comment(string $path, NewComment $data): Comment
    {
        $this->guardSupported(Feature::CreateComment);
        $this->guardAuthenticated();

        $response = $this->send('POST', $this->repositoryUrl($path)."/pullrequests/{$data->number}/comments", [
            'content' => ['raw' => $data->body],
        ]);

        $comment = $response->json();

        return new Comment(
            id: (string) $comment['id'],
            body: $comment['content']['raw'] ?? $data->body,
            author: isset($comment['user']) ? new Author(
                name: $comment['user']['display_name'] ?? '',
                email: '',
                avatar: $comment['user']['links']['avatar']['href'] ?? null,
            ) : null,
            url: $comment['links']['html']['href'] ?? null,
            createdAt: Carbon::parse($comment['created_on']),
        );
    }

    public function createWebhook(string $path, NewWebhook $data): Webhook
    {
        $this->guardSupported(Feature::CreateWebhook);
        $this->guardAuthenticated();

        $response = $this->send('POST', $this->repositoryUrl($path).'/hooks', [
            'description' => 'git-for-laravel',
            'url' => $data->url,
            'active' => $data->active,
            'events' => $this->mapWebhookEvents($data->events),
            // Without it Bitbucket signs nothing, and the package's own route — which
            // requires `X-Hub-Signature` — answers 403 to every delivery.
            ...($data->secret !== null ? ['secret' => $data->secret] : []),
        ]);

        $hook = $response->json();

        return new Webhook(
            provider: $this->providerName(),
            id: (string) $hook['uuid'],
            url: $hook['url'] ?? $data->url,
            events: $data->events,
            active: (bool) ($hook['active'] ?? $data->active),
            raw: $hook,
        );
    }

    public function deleteWebhook(string $path, string $id): void
    {
        $this->guardSupported(Feature::DeleteWebhook);
        $this->guardAuthenticated();

        $this->send('DELETE', $this->repositoryUrl($path).'/hooks/'.$this->webhookSegment($id));
    }

    /** @return list<Webhook> */
    public function listWebhooks(string $path): array
    {
        $this->guardSupported(Feature::ListWebhooks);
        $this->guardAuthenticated();

        /** @var list<array<string, mixed>> $hooks */
        $hooks = $this->get($this->repositoryUrl($path).'/hooks')->json('values') ?? [];

        return array_map(fn (array $hook): Webhook => new Webhook(
            provider: $this->providerName(),
            id: (string) $hook['uuid'],
            url: $hook['url'] ?? '',
            events: $this->canonicalWebhookEvents(is_array($hook['events'] ?? null) ? $hook['events'] : []),
            active: (bool) ($hook['active'] ?? true),
            raw: $hook,
        ), $hooks);
    }

    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string
    {
        return $this->buildCloneUrl(
            baseUrl: $this->cloneBaseUrl(),
            user: $username,
            secret: $this->cloneSecretFor($credentials),
            path: $path,
        );
    }

    protected function cloneBaseUrl(): string
    {
        return $this->webUrl();
    }

    protected function pageParameters(int $page, int $perPage): array
    {
        return ['page' => $page, 'pagelen' => $perPage];
    }

    protected function hasMorePages(Response $response, int $count, int $perPage): bool
    {
        return is_string($response->json('next'));
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
            Feature::CreateRepository,
            Feature::CreatePullRequest,
            Feature::CreateComment,
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
        ];
    }

    protected function mapState(string $state): string
    {
        return match ($state) {
            'open' => 'OPEN',
            'closed' => 'DECLINED',
            'merged' => 'MERGED',
            default => strtoupper($state),
        };
    }

    /**
     * The canonical event names in Bitbucket's own; a native `scope:action` passes through.
     *
     * `pull_request` is every transition the package's route reports as a pull request
     * event — opened, updated, merged and declined — as GitHub's `pull_request` is, not
     * just the opening.
     *
     * @param  list<string>  $events
     * @return list<string>
     */
    protected function mapWebhookEvents(array $events): array
    {
        $mapped = [];

        foreach ($events as $event) {
            foreach (self::WEBHOOK_EVENTS[$event] ?? [$event] as $native) {
                $mapped[] = $native;
            }
        }

        return array_values(array_unique($mapped));
    }

    /**
     * Bitbucket's event names read back as the canonical ones `createWebhook()` takes.
     *
     * @param  array<mixed>  $events
     * @return list<string>
     */
    protected function canonicalWebhookEvents(array $events): array
    {
        $canonical = [];

        foreach ($events as $event) {
            if (! is_string($event)) {
                continue;
            }

            $name = $event;

            foreach (self::WEBHOOK_EVENTS as $candidate => $natives) {
                if (in_array($event, $natives, true)) {
                    $name = $candidate;

                    break;
                }
            }

            $canonical[] = $name;
        }

        return array_values(array_unique($canonical));
    }
}
