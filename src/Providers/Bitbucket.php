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

class Bitbucket extends BaseProvider
{
    public function user(): Owner
    {
        $user = $this->bitbucket()
            ->get('/2.0/user')
            ->json();

        return new Owner(
            id: (string) $user['uuid'],
            name: $user['username'],
            avatar: $user['links']['avatar']['href'] ?? null,
        );
    }

    /** @return Collection<int, Repository> */
    public function repositories(): Collection
    {
        return $this->bitbucket()
            ->get('/2.0/repositories')
            ->collect('values')
            ->map(fn (array $repository): Repository => $this->createRepositoryDtoFromBitbucket($repository))
            ->values();
    }

    public function repository(string $path): Repository
    {
        $repository = $this->bitbucket()
            ->get("/2.0/repositories/$path")
            ->json();

        return $this->createRepositoryDtoFromBitbucket($repository);
    }

    /** @return list<string> */
    public function branches(string $path): array
    {
        return array_values(
            $this->bitbucket()
                ->get("/2.0/repositories/$path/refs/branches?limit=1000")
                ->collect('values')
                ->map(fn (array $item): string => $item['displayId'])
                ->all()
        );
    }

    /** @return Collection<int, Commit> */
    public function commits(string $path, string $branch, int $page = 1): Collection
    {
        return $this->bitbucket()
            ->get("/2.0/repositories/$path/commits?include=$branch&page=$page")
            ->collect('values')
            ->map(fn (array $commit): Commit => $this->createCommitDtoFromBitbucket($commit))
            ->values();
    }

    public function commit(string $path, string $commit): Commit
    {
        $commit = $this->bitbucket()
            ->get("/2.0/repositories/$path/commit/$commit")
            ->json();

        return $this->createCommitDtoFromBitbucket($commit);
    }

    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string
    {
        return $this->buildCloneUrl(
            baseUrl: (string) config('git.providers.bitbucket.url'),
            user: $username,
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

    protected function bitbucket(): PendingRequest
    {
        $this->enforceRateLimit('bitbucket', $this->rateLimitFromConfig('bitbucket'));

        /** @var array<string, mixed> $http */
        $http = config('git.providers.bitbucket', []);

        return Http::withOptions($http['options'] ?? [])
            ->timeout($http['timeout'] ?? 10)
            ->retry($http['retry'] ?? 1)
            ->throw()
            ->withToken((string) $this->authentication?->credentials?->getValue())
            ->baseUrl($http['url'] ?? 'https://api.bitbucket.org')
            ->acceptJson()
            ->asJson();
    }

    /** @param array<string, mixed> $repository */
    protected function createRepositoryDtoFromBitbucket(array $repository): Repository
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
    protected function createCommitDtoFromBitbucket(array $commit): Commit
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
}
