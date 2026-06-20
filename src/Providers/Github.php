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

class Github extends BaseProvider
{
    public function name(): string
    {
        return 'GitHub';
    }

    public function user(): Owner
    {
        $user = $this->github()
            ->get('/user')
            ->json();

        return new Owner(
            id: (string) $user['id'],
            name: $user['login'],
            avatar: $user['avatar_url'] ?? null,
        );
    }

    /** @return Collection<int, Repository> */
    public function repositories(): Collection
    {
        return $this->github()
            ->get('/user/repos')
            ->collect()
            ->map(fn (array $repository): Repository => $this->createRepositoryDtoFromGithub($repository))
            ->values();
    }

    public function repository(string $path): Repository
    {
        $repository = $this->github()
            ->get("/repos/$path")
            ->json();

        return $this->createRepositoryDtoFromGithub($repository);
    }

    /** @return list<string> */
    public function branches(string $path): array
    {
        return array_values(
            $this->github()
                ->get("/repos/$path/branches")
                ->collect()
                ->map(fn (array $item): string => $item['name'])
                ->all()
        );
    }

    /** @return Collection<int, Commit> */
    public function commits(string $path, string $branch, int $page = 1): Collection
    {
        return $this->github()
            ->get("/repos/$path/commits?sha=$branch&page=$page")
            ->collect()
            ->map(fn (array $commit): Commit => $this->createCommitDtoFromGithub($commit))
            ->values();
    }

    public function commit(string $path, string $commit): Commit
    {
        $commit = $this->github()
            ->get("/repos/$path/commits/$commit")
            ->json();

        return $this->createCommitDtoFromGithub($commit);
    }

    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string
    {
        $url = str((string) config('git.providers.github.url'))->replace('api.', '')->toString();

        return $this->buildCloneUrl(
            baseUrl: $url,
            user: 'token',
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

    protected function github(): PendingRequest
    {
        $this->enforceRateLimit('github', $this->rateLimitFromConfig('github'));

        /** @var array<string, mixed> $http */
        $http = config('git.providers.github', []);

        return Http::withOptions($http['options'] ?? [])
            ->timeout($http['timeout'] ?? 10)
            ->retry($http['retry'] ?? 1)
            ->throw()
            ->withToken((string) $this->authentication?->credentials?->getValue())
            ->baseUrl($http['url'] ?? 'https://api.github.com')
            ->acceptJson()
            ->asJson();
    }

    /** @param array<string, mixed> $repository */
    protected function createRepositoryDtoFromGithub(array $repository): Repository
    {
        return new Repository(
            id: (string) $repository['id'],
            path: $repository['full_name'],
            name: $repository['name'],
            description: $repository['description'] ?? null,
            defaultBranch: $repository['default_branch'],
            owner: new Owner(
                id: (string) $repository['owner']['id'],
                name: $repository['owner']['login'],
                avatar: $repository['owner']['avatar_url'] ?? null,
            ),
            createdAt: $createdAt = Carbon::parse($repository['created_at']),
            lastActivityAt: $repository['pushed_at'] ? Carbon::parse($repository['pushed_at']) : $createdAt,
        );
    }

    /** @param array<string, mixed> $commit */
    protected function createCommitDtoFromGithub(array $commit): Commit
    {
        return new Commit(
            sha: $commit['sha'],
            message: $commit['commit']['message'],
            author: new Author(
                name: $commit['commit']['author']['name'],
                email: $commit['commit']['author']['email'],
                avatar: $commit['author']['avatar_url'] ?? null,
            ),
            url: $commit['html_url'] ?? null,
            commitAt: Carbon::parse($commit['commit']['author']['date']),
        );
    }
}
