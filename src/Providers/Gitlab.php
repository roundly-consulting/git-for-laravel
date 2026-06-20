<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Providers;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\Feature;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Repository;

class Gitlab extends BaseProvider
{
    public function name(): string
    {
        return 'GitLab';
    }

    public function user(): Owner
    {
        $user = $this->gitlab()
            ->get('/api/v4/user')
            ->json();

        return new Owner(
            id: (string) $user['id'],
            name: $user['username'],
            avatar: $user['avatar_url'] ?? null,
        );
    }

    /** @return Collection<int, Repository> */
    public function repositories(): Collection
    {
        return $this->gitlab()
            ->get('/api/v4/projects')
            ->collect()
            ->map(fn (array $repository): Repository => $this->createRepositoryDtoFromGitlab($repository))
            ->values();
    }

    public function repository(string $path): Repository
    {
        $repository = $this->gitlab()
            ->withUrlParameters(['id' => $path])
            ->get('/api/v4/projects/{id}')
            ->json();

        return $this->createRepositoryDtoFromGitlab($repository);
    }

    /** @return list<string> */
    public function branches(string $path): array
    {
        return array_values(
            $this->gitlab()
                ->withUrlParameters(['id' => $path])
                ->get('/api/v4/projects/{id}/repository/branches')
                ->collect()
                ->map(fn (array $item): string => $item['name'])
                ->all()
        );
    }

    /** @return Collection<int, Commit> */
    public function commits(string $path, string $branch, int $page = 1): Collection
    {
        return $this->gitlab()
            ->withUrlParameters(['id' => $path])
            ->get("/api/v4/projects/{id}/repository/commits/$branch?page=$page")
            ->collect()
            ->map(fn (array $commit): Commit => $this->createCommitDtoFromGitlab($commit))
            ->values();
    }

    public function commit(string $path, string $commit): Commit
    {
        $commit = $this->gitlab()
            ->withUrlParameters(['id' => $path])
            ->get("/api/v4/projects/{id}/repository/commits/$commit")
            ->json();

        return $this->createCommitDtoFromGitlab($commit);
    }

    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string
    {
        return $this->buildCloneUrl(
            baseUrl: (string) config('git.providers.gitlab.url'),
            user: 'oauth2',
            secret: (string) $credentials->credentials?->getValue(),
            path: $path,
        );
    }

    /** @return list<Feature> */
    public function features(): array
    {
        return [
            Feature::listRepositories(),
            Feature::findRepository(),
            Feature::listCommits(),
            Feature::findCommit(),
            Feature::listRepositoryBranches(),
        ];
    }

    /** @return list<class-string<Credentials>> */
    public function authenticationMethods(): array
    {
        return [
            Token::class,
        ];
    }

    protected function gitlab(): PendingRequest
    {
        $this->enforceRateLimit('gitlab', $this->rateLimitFromConfig('gitlab'));

        /** @var array<string, mixed> $http */
        $http = config('git.providers.gitlab', []);

        return Http::withOptions($http['options'] ?? [])
            ->timeout($http['timeout'] ?? 10)
            ->retry($http['retry'] ?? 1)
            ->throw()
            ->withToken((string) $this->authentication?->credentials?->getValue())
            ->baseUrl($http['url'] ?? 'https://gitlab.com')
            ->acceptJson()
            ->asJson();
    }

    /** @param array<string, mixed> $repository */
    protected function createRepositoryDtoFromGitlab(array $repository): Repository
    {
        return new Repository(
            id: (string) $repository['id'],
            path: $repository['path_with_namespace'],
            name: $repository['path'],
            description: $repository['description'] ?? null,
            defaultBranch: $repository['default_branch'],
            owner: new Owner(
                id: (string) $repository['namespace']['id'],
                name: $repository['namespace']['path'],
                avatar: $repository['namespace']['avatar_url'] ?? null,
            ),
            createdAt: $createdAt = Carbon::parse($repository['created_at']),
            lastActivityAt: $repository['last_activity_at'] ? Carbon::parse($repository['last_activity_at']) : $createdAt,
        );
    }

    /** @param array<string, mixed> $commit */
    protected function createCommitDtoFromGitlab(array $commit): Commit
    {
        return new Commit(
            sha: $commit['id'],
            message: $commit['message'],
            author: new Author(
                name: $commit['author_name'],
                email: $commit['author_email'],
                avatar: null,
            ),
            url: $commit['web_url'] ?? null,
            commitAt: Carbon::parse($commit['authored_date']),
        );
    }
}
