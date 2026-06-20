<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Interfaces;

use Illuminate\Support\Collection;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Feature;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Repository;

interface Provider
{
    public function name(): string;

    public function description(): string;

    /** @return list<Feature> */
    public function features(): array;

    public function supports(string $feature): bool;

    /** @return list<class-string<Credentials>> */
    public function authenticationMethods(): array;

    public function authenticate(Credentials $credentials): self;

    public function isAuthenticated(): bool;

    public function user(): Owner;

    /** @return Collection<int, Repository> */
    public function repositories(): Collection;

    public function repository(string $path): Repository;

    /** @return list<string> */
    public function branches(string $path): array;

    /** @return Collection<int, Commit> */
    public function commits(string $path, string $branch, int $page = 1): Collection;

    public function commit(string $path, string $commit): Commit;

    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string;
}
