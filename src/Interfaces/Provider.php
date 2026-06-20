<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Interfaces;

use Illuminate\Support\LazyCollection;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Dto\RateLimitStatus;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\ProviderName;

interface Provider
{
    public function name(): string;

    public function description(): string;

    public function providerName(): ProviderName;

    /** @return list<Feature> */
    public function features(): array;

    public function supports(Feature $feature): bool;

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
}
