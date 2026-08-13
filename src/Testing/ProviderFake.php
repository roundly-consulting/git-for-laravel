<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Testing;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\LazyCollection;
use RoundlyConsulting\Git\Batch\Batch;
use RoundlyConsulting\Git\Batch\BatchError;
use RoundlyConsulting\Git\Batch\BatchResult;
use RoundlyConsulting\Git\Contracts\RefreshableCredentials;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Credentials\GithubApp;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\FeatureInfo;
use RoundlyConsulting\Git\Dto\FileContent;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Dto\RateLimitStatus;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Interfaces\Provider;
use RoundlyConsulting\Git\Mapping\ResourceMapper;
use RoundlyConsulting\Git\Webhooks\Webhooks;
use RuntimeException;

/**
 * In-memory provider double for testing host applications without real HTTP.
 */
final class ProviderFake implements Provider
{
    /** @var array<string, mixed> */
    private array $seeded = [];

    /** @var array<string, BatchResult<mixed>> */
    private array $seededBatches = [];

    public function __construct(
        private readonly ProviderName $name,
        private readonly RegistryFake $registry,
    ) {}

    /** @param BatchResult<mixed> $result */
    public function seedBatch(string $method, BatchResult $result): self
    {
        $this->seededBatches[$method] = $result;

        return $this;
    }

    /** @param list<Repository> $repositories */
    public function seedRepositories(array $repositories): self
    {
        $this->seeded['repositories'] = $repositories;

        return $this;
    }

    /** @param list<Commit> $commits */
    public function seedCommits(array $commits): self
    {
        $this->seeded['commits'] = $commits;

        return $this;
    }

    public function seedUser(Owner $user): self
    {
        $this->seeded['user'] = $user;

        return $this;
    }

    public function seedRepository(Repository $repository): self
    {
        $this->seeded['repository'] = $repository;

        return $this;
    }

    public function seedInstallation(Installation $installation): self
    {
        $this->seeded['installation'] = $installation;

        return $this;
    }

    /** @param list<Installation> $installations */
    public function seedInstallations(array $installations): self
    {
        $this->seeded['installations'] = $installations;

        return $this;
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
        return Feature::cases();
    }

    public function supports(Feature $feature): bool
    {
        return true;
    }

    /** @return list<class-string<Credentials>> */
    public function authenticationMethods(): array
    {
        return [];
    }

    public function authenticate(Credentials $credentials): self
    {
        return $this;
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function rateLimit(): ?RateLimitStatus
    {
        return null;
    }

    public function user(): Owner
    {
        $this->record('user', []);

        /** @var Owner */
        return $this->seeded['user'] ?? new Owner(id: 'fake', name: 'fake', avatar: null);
    }

    /** @return Page<Repository> */
    public function repositories(int $perPage = 30): Page
    {
        $this->record('repositories', [$perPage]);

        /** @var list<Repository> $items */
        $items = $this->seeded['repositories'] ?? [];

        return new Page(items: $items, perPage: $perPage, page: 1, hasMore: false);
    }

    /** @return LazyCollection<int, Repository> */
    public function allRepositories(int $perPage = 30): LazyCollection
    {
        $this->record('allRepositories', [$perPage]);

        /** @var list<Repository> $items */
        $items = $this->seeded['repositories'] ?? [];

        return LazyCollection::make($items);
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
        $this->record('installationRepositories', [$perPage]);

        /** @var list<Repository> $items */
        $items = $this->seeded['repositories'] ?? [];

        return new Page(items: $items, perPage: $perPage, page: 1, hasMore: false);
    }

    /** @return LazyCollection<int, Repository> */
    public function allInstallationRepositories(int $perPage = 30): LazyCollection
    {
        $this->record('allInstallationRepositories', [$perPage]);

        /** @var list<Repository> $items */
        $items = $this->seeded['repositories'] ?? [];

        return LazyCollection::make($items);
    }

    public function installation(string $id): Installation
    {
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
    public function installations(int $perPage = 30): Page
    {
        $this->record('installations', [$perPage]);

        /** @var list<Installation> $items */
        $items = $this->seeded['installations']
            ?? (isset($this->seeded['installation']) ? [$this->seededInstallation()] : []);

        return new Page(items: $items, perPage: $perPage, page: 1, hasMore: false);
    }

    public function organizationInstallation(string $organization): Installation
    {
        $this->record('organizationInstallation', [$organization]);

        return $this->seededInstallation();
    }

    public function userInstallation(string $login): Installation
    {
        $this->record('userInstallation', [$login]);

        return $this->seededInstallation();
    }

    private function seededInstallation(): Installation
    {
        if (! isset($this->seeded['installation'])) {
            throw new RuntimeException("No installation seeded on the [{$this->name()}] fake.");
        }

        /** @var Installation */
        return $this->seeded['installation'];
    }

    public function installUrl(?string $state = null): string
    {
        $this->record('installUrl', [$state]);

        $slug = config("git.providers.{$this->name->key()}.app.slug") ?: 'fake-app';

        return "https://fake/apps/{$slug}/installations/new".($state === null ? '' : '?state='.urlencode($state));
    }

    public function repository(string $path): Repository
    {
        $this->record('repository', [$path]);

        if (! isset($this->seeded['repository'])) {
            throw new RuntimeException("No repository seeded on the [{$this->name()}] fake.");
        }

        /** @var Repository */
        return $this->seeded['repository'];
    }

    /** @return Page<string> */
    public function branches(string $path, int $perPage = 30): Page
    {
        $this->record('branches', [$path, $perPage]);

        /** @var list<string> $items */
        $items = $this->seeded['branches'] ?? [];

        return new Page(items: $items, perPage: $perPage, page: 1, hasMore: false);
    }

    public function commit(string $path, string $commit): Commit
    {
        $this->record('commit', [$path, $commit]);

        if (! isset($this->seeded['commit'])) {
            throw new RuntimeException("No commit seeded on the [{$this->name()}] fake.");
        }

        /** @var Commit */
        return $this->seeded['commit'];
    }

    public function createRepository(NewRepository $data): Repository
    {
        $this->record('createRepository', [$data]);

        return $this->seeded['createRepository'] ?? new Repository(
            provider: $this->name,
            id: 'fake',
            path: $data->name,
            name: $data->name,
            description: $data->description,
            defaultBranch: 'main',
            owner: new Owner(id: 'fake', name: 'fake', avatar: null),
            createdAt: Carbon::now(),
            lastActivityAt: Carbon::now(),
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

        if ($credentials instanceof GithubAppToken) {
            return "https://x-access-token:ghs_fake@fake/{$path}.git";
        }

        $secret = $credentials instanceof RefreshableCredentials
            ? 'fake-refreshed'
            : (string) $credentials->credentials?->getValue();

        return $secret === ''
            ? "https://{$username}@fake/{$path}.git"
            : "https://{$username}:{$secret}@fake/{$path}.git";
    }

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        $capabilities = [];

        foreach (Feature::cases() as $feature) {
            $capabilities[$feature->value] = true;
        }

        return $capabilities;
    }

    public function supportsAll(Feature ...$features): bool
    {
        return true;
    }

    public function supportsAny(Feature ...$features): bool
    {
        return $features !== [];
    }

    /** @return list<FeatureInfo> */
    public function featureMatrix(): array
    {
        return array_map(fn (Feature $feature): FeatureInfo => $feature->info(true), Feature::cases());
    }

    public function batch(): Batch
    {
        return new BatchFake($this->name, $this->registry, $this->seededBatches);
    }

    public function webhooks(string $path): Webhooks
    {
        return new Webhooks($this, $path);
    }

    /**
     * @param  array<string, array{url: string, query: array<string, mixed>}>  $specs
     * @return array<string, Response|BatchError>
     */
    public function runPool(array $specs): array
    {
        return [];
    }

    public function mapResource(): ResourceMapper
    {
        throw new RuntimeException('mapResource() is not available on the provider fake.');
    }

    public function repositoryUrl(string $path): string
    {
        return $path;
    }

    public function languagesUrl(string $path): string
    {
        return $path;
    }

    public function pullRequestUrl(string $path, int $number): string
    {
        return "{$path}#{$number}";
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    public function contentsRequest(string $path, string $filePath, ?string $ref = null): array
    {
        return [$filePath, []];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, int>
     */
    public function normalizeLanguages(array $raw): array
    {
        /** @var array<string, int> $languages */
        $languages = array_map(fn (mixed $value): int => (int) $value, $raw);

        return $languages;
    }

    /** @param array<string, mixed> $raw */
    public function mapFileContent(array $raw): FileContent
    {
        throw new RuntimeException('mapFileContent() is not available on the provider fake.');
    }

    public function createWebhook(string $path, NewWebhook $data): Webhook
    {
        $this->record('createWebhook', [$path, $data]);

        /** @var Webhook|null $seeded */
        $seeded = $this->seeded['createWebhook'] ?? null;

        return $seeded ?? new Webhook(
            provider: $this->name,
            id: 'fake',
            url: $data->url,
            events: $data->events,
            active: $data->active,
        );
    }

    public function deleteWebhook(string $path, string $id): void
    {
        $this->record('deleteWebhook', [$path, $id]);
    }

    /** @return list<Webhook> */
    public function listWebhooks(string $path): array
    {
        $this->record('listWebhooks', [$path]);

        /** @var list<Webhook> $hooks */
        $hooks = $this->seeded['webhooks'] ?? [];

        return $hooks;
    }

    /** @param list<Webhook> $webhooks */
    public function seedWebhooks(array $webhooks): self
    {
        $this->seeded['webhooks'] = $webhooks;

        return $this;
    }

    /** @param list<mixed> $arguments */
    private function record(string $method, array $arguments): void
    {
        $this->registry->record($this->name, new RecordedCall($method, $arguments));
    }
}
