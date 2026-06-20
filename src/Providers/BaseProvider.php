<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Providers;

use Illuminate\Support\Collection;
use RoundlyConsulting\Git\Concerns\InteractsWithRateLimits;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Feature;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Interfaces\Provider;

abstract class BaseProvider implements Provider
{
    use InteractsWithRateLimits;

    protected ?Credentials $authentication = null;

    public function name(): string
    {
        return class_basename($this);
    }

    public function description(): string
    {
        return "{$this->name()} Provider";
    }

    public function user(): Owner
    {
        return new Owner(
            id: 'unknown',
            name: 'Unknown',
            avatar: null,
        );
    }

    public function supports(string $feature): bool
    {
        return collect($this->features())->contains('id', '=', $feature);
    }

    /** @return list<Feature> */
    public function features(): array
    {
        return [];
    }

    /** @return list<class-string<Credentials>> */
    public function authenticationMethods(): array
    {
        return [];
    }

    public function authenticate(Credentials $credentials): self
    {
        $this->guardAgainstInvalidCredentialsType($credentials);

        $this->authentication = $credentials;

        return $this;
    }

    public function isAuthenticated(): bool
    {
        return ! is_null($this->authentication);
    }

    /** @return Collection<int, Repository> */
    public function repositories(): Collection
    {
        $this->featureNotSupported();
    }

    public function repository(string $path): Repository
    {
        $this->featureNotSupported();
    }

    /** @return list<string> */
    public function branches(string $path): array
    {
        $this->featureNotSupported();
    }

    /** @return Collection<int, Commit> */
    public function commits(string $path, string $branch, int $page = 1): Collection
    {
        $this->featureNotSupported();
    }

    public function commit(string $path, string $commit): Commit
    {
        $this->featureNotSupported();
    }

    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string
    {
        $this->featureNotSupported();
    }

    protected function buildCloneUrl(string $baseUrl, string $user, string $secret, string $path): string
    {
        $host = str($baseUrl)->after('://')->rtrim('/')->toString();
        $scheme = str($baseUrl)->before('://')->toString();

        return "{$scheme}://{$user}:{$secret}@{$host}/{$path}.git";
    }

    protected function featureNotSupported(): never
    {
        throw FeatureNotSupportedException::for(
            feature: __METHOD__,
            provider: $this->name(),
        );
    }

    protected function guardAgainstInvalidCredentialsType(Credentials $credentials): void
    {
        $credentialsType = get_class($credentials);
        $supportedAuthenticationMethods = $this->authenticationMethods();

        if (! in_array($credentialsType, $supportedAuthenticationMethods)) {
            throw InvalidCredentialsException::unsupported(
                provider: $this->name(),
                credentials: $credentialsType,
                supported: $supportedAuthenticationMethods,
            );
        }
    }
}
