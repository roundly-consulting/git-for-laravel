<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use RoundlyConsulting\Crypto\Hash\Digest;
use RoundlyConsulting\Git\Dto\Dto;

/**
 * What a GitHub App installation token is allowed to reach.
 *
 * An installation token minted with no scope carries EVERY repository the app is
 * installed on, for an hour. Naming the repositories (and the permissions) narrows it
 * to the one operation being performed, which is the whole point of minting per
 * operation rather than storing a credential.
 *
 * `repositoryIds` (GitHub's numeric ids) is preferred over `repositories`
 * ("owner/name") because a rename does not invalidate it — but the two are equally
 * NARROW, so falling back to names is safe where the id is unknown. Falling back to
 * an EMPTY scope is not, which is why {@see isEmpty()} exists and callers are
 * expected to refuse rather than widen.
 */
final readonly class InstallationTokenScope extends Dto
{
    /**
     * @param  list<string>  $repositoryIds  GitHub numeric repository ids
     * @param  list<string>  $repositories  "owner/name" selectors
     * @param  array<string, string>  $permissions  provider-defined map, e.g. ['contents' => 'write']
     */
    public function __construct(
        public array $repositoryIds = [],
        public array $repositories = [],
        public array $permissions = [],
    ) {}

    /**
     * A scope over specific repositories, with the deployment's configured permission
     * set (`git.providers.github.app.permissions`).
     *
     * The permissions live in config rather than at the call site because they are a
     * property of what the APP was granted, not of one operation — and because a call
     * site that spells them out drifts from the app's real permission set silently, in
     * the direction of asking for something that no longer exists.
     *
     * @param  list<string>  $repositoryIds
     * @param  list<string>  $repositories
     */
    public static function forRepositories(
        array $repositoryIds = [],
        array $repositories = [],
        string $provider = 'github',
    ): self {
        /** @var array<string, string> $permissions */
        $permissions = config("git.providers.{$provider}.app.permissions", []);

        return new self(
            repositoryIds: $repositoryIds,
            repositories: $repositories,
            permissions: $permissions,
        );
    }

    /** No repository selector at all — a token minted from this reaches the whole installation. */
    public function isEmpty(): bool
    {
        return $this->repositoryIds === [] && $this->repositories === [];
    }

    /**
     * The request-body fragment for POST /app/installations/{id}/access_tokens.
     *
     * Empty members are omitted: GitHub reads `"repositories": []` as "no repositories"
     * rather than "unspecified", which mints a token that can reach nothing.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        $payload = [];

        if ($this->repositoryIds !== []) {
            // Numeric, because GitHub rejects string ids on this field.
            $payload['repository_ids'] = array_map(intval(...), $this->repositoryIds);
        }

        if ($this->repositories !== []) {
            // GitHub wants the repository NAME here, not "owner/name" — an installation
            // already belongs to exactly one account, so the owner is implied and
            // sending it produces a 422 that reads like a permissions problem.
            $payload['repositories'] = array_map(
                fn (string $repository): string => str_contains($repository, '/')
                    ? substr($repository, strrpos($repository, '/') + 1)
                    : $repository,
                $this->repositories,
            );
        }

        if ($this->permissions !== []) {
            $payload['permissions'] = $this->permissions;
        }

        return $payload;
    }

    /**
     * A stable fingerprint of this scope, used to key the token cache.
     *
     * ORDER-INSENSITIVE on purpose: the same scope expressed with its lists in a
     * different order must hit the same cache entry, or every mint is a fresh API call
     * against a rate limit that is shared per installation.
     */
    public function digest(): string
    {
        $ids = $this->repositoryIds;
        $names = $this->repositories;
        $permissions = $this->permissions;

        sort($ids);
        sort($names);
        ksort($permissions);

        return (new Digest)->hex((string) json_encode([$ids, $names, $permissions]));
    }
}
