<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Interfaces;

use Illuminate\Http\Client\Response;
use Illuminate\Support\LazyCollection;
use RoundlyConsulting\Git\Batch\Batch;
use RoundlyConsulting\Git\Batch\BatchError;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\FeatureInfo;
use RoundlyConsulting\Git\Dto\FileContent;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Dto\RateLimitStatus;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Mapping\ResourceMapper;
use RoundlyConsulting\Git\Webhooks\Webhooks;

interface Provider
{
    public function name(): string;

    public function description(): string;

    public function providerName(): ProviderName;

    /** @return list<Feature> */
    public function features(): array;

    public function supports(Feature $feature): bool;

    /** @return array<string, bool> */
    public function capabilities(): array;

    public function supportsAll(Feature ...$features): bool;

    public function supportsAny(Feature ...$features): bool;

    /** @return list<FeatureInfo> */
    public function featureMatrix(): array;

    public function batch(): Batch;

    public function webhooks(string $path): Webhooks;

    /**
     * @param  array<string, array{url: string, query: array<string, mixed>}>  $specs
     * @return array<string, Response|BatchError>
     */
    public function runPool(array $specs): array;

    public function mapResource(): ResourceMapper;

    public function repositoryUrl(string $path): string;

    public function languagesUrl(string $path): string;

    public function pullRequestUrl(string $path, int $number): string;

    /** @return array{0: string, 1: array<string, mixed>} */
    public function contentsRequest(string $path, string $filePath, ?string $ref = null): array;

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, int>
     */
    public function normalizeLanguages(array $raw): array;

    /** @param array<string, mixed> $raw */
    public function mapFileContent(array $raw): FileContent;

    public function createWebhook(string $path, NewWebhook $data): Webhook;

    public function deleteWebhook(string $path, string $id): void;

    /** @return list<Webhook> */
    public function listWebhooks(string $path): array;

    /** @return list<class-string<Credentials>> */
    public function authenticationMethods(): array;

    public function authenticate(Credentials $credentials): self;

    public function isAuthenticated(): bool;

    public function rateLimit(): ?RateLimitStatus;

    public function user(): Owner;

    /** @return Page<Repository> */
    public function repositories(int $perPage = 30): Page;

    /** @return LazyCollection<int, Repository> */
    public function allRepositories(int $perPage = 30): LazyCollection;

    public function repository(string $path): Repository;

    /** @return Page<string> */
    public function branches(string $path, int $perPage = 30): Page;

    public function commit(string $path, string $commit): Commit;

    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string;

    /**
     * The repositories reachable by the credential's own installation.
     *
     * On the shared interface (rather than only on the GitHub provider) so a host
     * application can drive it through `Registry::fake()`. Providers without app
     * installations answer `FeatureNotSupportedException`.
     *
     * @return Page<Repository>
     */
    public function installationRepositories(int $perPage = 30): Page;

    /** @return LazyCollection<int, Repository> */
    public function allInstallationRepositories(int $perPage = 30): LazyCollection;

    /** One app installation, looked up as the app itself. */
    public function installation(string $id): Installation;

    /**
     * Every account this app is installed on, looked up as the app itself.
     *
     * On the shared interface for the same reason `installation()` is: `Registry::githubApp()`
     * is typed to THIS interface, so a method only the concrete GitHub provider declares is
     * unreachable without an `instanceof` narrowing — and a provider without app installations
     * answers a fatal "undefined method" instead of `FeatureNotSupportedException`.
     *
     * @return Page<Installation>
     */
    public function installations(int $perPage = 30): Page;

    /** This app's installation on an organization, looked up as the app itself. */
    public function organizationInstallation(string $organization): Installation;

    /** This app's installation on a user account, looked up as the app itself. */
    public function userInstallation(string $login): Installation;

    /**
     * Where to send a human to install this provider's app, carrying `state`.
     *
     * On the shared interface for the same reason the two above are: it is the entry point
     * of a consumer's redirect flow, so it must be drivable through `Registry::fake()` and
     * callable on the `Provider` type without an `instanceof` narrowing at every call site.
     */
    public function installUrl(?string $state = null): string;
}
