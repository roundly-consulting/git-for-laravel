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
use RoundlyConsulting\Git\Query\CommitQuery;

class Bitbucket extends BaseProvider
{
    protected function key(): string
    {
        return 'bitbucket';
    }

    public function user(): Owner
    {
        $user = $this->get('/2.0/user')->json();

        return new Owner(
            id: (string) $user['uuid'],
            name: $user['username'],
            avatar: $user['links']['avatar']['href'] ?? null,
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
            map: fn (array $repository): Repository => $this->createRepositoryDto($repository),
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
            map: fn (array $repository): Repository => $this->createRepositoryDto($repository),
            itemsKey: 'values',
        ));
    }

    public function repository(string $path): Repository
    {
        return $this->createRepositoryDto($this->get("/2.0/repositories/{$path}")->json());
    }

    /** @return Page<string> */
    public function branches(string $path, int $perPage = 30): Page
    {
        return $this->paginate(
            url: "/2.0/repositories/{$path}/refs/branches",
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
                url: "/2.0/repositories/{$path}/commits",
                query: $query,
                page: $page,
                perPage: $perPage,
                map: fn (array $commit): Commit => $this->createCommitDto($commit),
                itemsKey: 'values',
            );
        });
    }

    public function commit(string $path, string $commit): Commit
    {
        return $this->createCommitDto($this->get("/2.0/repositories/{$path}/commit/{$commit}")->json());
    }

    /** @return Page<PullRequest> */
    public function pullRequests(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->guardSupported(Feature::ListPullRequests);

        return $this->paginate(
            url: "/2.0/repositories/{$path}/pullrequests",
            query: ['state' => $this->mapState($state)],
            page: 1,
            perPage: $perPage,
            map: fn (array $pr): PullRequest => $this->createPullRequestDto($pr),
            itemsKey: 'values',
        );
    }

    public function pullRequest(string $path, int $number): PullRequest
    {
        $this->guardSupported(Feature::FindPullRequest);

        return $this->createPullRequestDto($this->get("/2.0/repositories/{$path}/pullrequests/{$number}")->json());
    }

    public function createRepository(NewRepository $data): Repository
    {
        $this->guardSupported(Feature::CreateRepository);
        $this->guardAuthenticated();

        $response = $this->send('POST', "/2.0/repositories/{$data->name}", [
            'scm' => 'git',
            'is_private' => $data->private,
            'description' => $data->description,
        ]);

        return $this->createRepositoryDto($response->json());
    }

    public function createPullRequest(string $path, NewPullRequest $data): PullRequest
    {
        $this->guardSupported(Feature::CreatePullRequest);
        $this->guardAuthenticated();

        $response = $this->send('POST', "/2.0/repositories/{$path}/pullrequests", [
            'title' => $data->title,
            'source' => ['branch' => ['name' => $data->head]],
            'destination' => ['branch' => ['name' => $data->base]],
            'description' => $data->body,
        ]);

        return $this->createPullRequestDto($response->json());
    }

    public function comment(string $path, NewComment $data): Comment
    {
        $this->guardSupported(Feature::CreateComment);
        $this->guardAuthenticated();

        $response = $this->send('POST', "/2.0/repositories/{$path}/pullrequests/{$data->number}/comments", [
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

        $response = $this->send('POST', "/2.0/repositories/{$path}/hooks", [
            'description' => 'git-for-laravel',
            'url' => $data->url,
            'active' => $data->active,
            'events' => $this->mapWebhookEvents($data->events),
        ]);

        $hook = $response->json();

        return new Webhook(
            id: (string) $hook['uuid'],
            url: $hook['url'] ?? $data->url,
            events: $data->events,
            active: (bool) ($hook['active'] ?? $data->active),
        );
    }

    public function deleteWebhook(string $path, string $id): void
    {
        $this->guardSupported(Feature::DeleteWebhook);
        $this->guardAuthenticated();

        $this->send('DELETE', "/2.0/repositories/{$path}/hooks/{$id}");
    }

    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string
    {
        return $this->buildCloneUrl(
            baseUrl: $this->cloneBaseUrl(),
            user: $username,
            secret: (string) $credentials->credentials?->getValue(),
            path: $path,
        );
    }

    protected function cloneBaseUrl(): string
    {
        return str((string) config('git.providers.bitbucket.url'))->replace('api.', '')->toString();
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
     * @param  list<string>  $events
     * @return list<string>
     */
    protected function mapWebhookEvents(array $events): array
    {
        return array_map(fn (string $event): string => match ($event) {
            'push' => 'repo:push',
            'pull_request' => 'pullrequest:created',
            default => $event,
        }, $events);
    }

    /** @param array<string, mixed> $repository */
    protected function createRepositoryDto(array $repository): Repository
    {
        return new Repository(
            id: (string) $repository['uuid'],
            path: $repository['full_name'],
            name: str($repository['full_name'])->after('/')->toString(),
            description: $repository['description'] ?? null,
            defaultBranch: $repository['mainbranch']['name'],
            owner: new Owner(
                id: (string) $repository['owner']['uuid'],
                name: $repository['owner']['username'],
                avatar: $repository['owner']['links']['avatar']['href'] ?? null,
            ),
            createdAt: $createdAt = Carbon::parse($repository['created_on']),
            lastActivityAt: $repository['updated_on'] ? Carbon::parse($repository['updated_on']) : $createdAt,
        );
    }

    /** @param array<string, mixed> $commit */
    protected function createCommitDto(array $commit): Commit
    {
        return new Commit(
            sha: $commit['hash'],
            message: $commit['message'],
            author: new Author(
                name: $commit['author']['user']['display_name'],
                email: str($commit['author']['raw'])->between('<', '>')->toString(),
                avatar: null,
            ),
            url: $commit['links']['html']['href'] ?? null,
            commitAt: Carbon::parse($commit['date']),
        );
    }

    /** @param array<string, mixed> $pr */
    protected function createPullRequestDto(array $pr): PullRequest
    {
        return new PullRequest(
            id: (string) $pr['id'],
            number: (int) $pr['id'],
            title: $pr['title'],
            body: $pr['description'] ?? null,
            state: $pr['state'],
            sourceBranch: $pr['source']['branch']['name'] ?? '',
            targetBranch: $pr['destination']['branch']['name'] ?? '',
            author: isset($pr['author']) ? new Author(
                name: $pr['author']['display_name'] ?? '',
                email: '',
                avatar: $pr['author']['links']['avatar']['href'] ?? null,
            ) : null,
            url: $pr['links']['html']['href'] ?? null,
            createdAt: Carbon::parse($pr['created_on']),
        );
    }
}
