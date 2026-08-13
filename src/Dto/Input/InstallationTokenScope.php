<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use RoundlyConsulting\Crypto\Hash\Digest;
use RoundlyConsulting\Git\Auth\TokenManager;
use RoundlyConsulting\Git\Dto\Dto;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;

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
 * an EMPTY scope is not: {@see isEmpty()} is what a caller checks, and
 * {@see TokenManager} refuses one outright rather than
 * quietly minting installation-wide.
 */
final readonly class InstallationTokenScope extends Dto
{
    /**
     * @param  list<string>  $repositoryIds  GitHub numeric repository ids
     * @param  list<string>  $repositories  "owner/name" selectors
     * @param  array<string, string>  $permissions  provider-defined map, e.g. ['contents' => 'write']
     * @param  bool  $installationWide  DELIBERATELY no repository selector — see {@see metadataOnly()}
     *
     * @throws InvalidCredentialsException when a repository id is not numeric
     */
    public function __construct(
        public array $repositoryIds = [],
        public array $repositories = [],
        public array $permissions = [],
        public bool $installationWide = false,
    ) {
        foreach ($repositoryIds as $id) {
            // A non-numeric id would become `0` on the way to GitHub's `repository_ids`,
            // which answers 422 — reported to whoever is reading the log as "the scope was
            // refused" rather than "that id was never a repository".
            if ($id === '' || ! ctype_digit($id)) {
                throw InvalidCredentialsException::invalidRepositorySelector($id);
            }
        }
    }

    /**
     * A scope over specific repositories, with the deployment's configured permission
     * set (`git.providers.<provider>.app.permissions`).
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
        ProviderName $provider = ProviderName::Github,
    ): self {
        /** @var array<string, string> $permissions */
        $permissions = config("git.providers.{$provider->key()}.app.permissions", []);

        return new self(
            repositoryIds: $repositoryIds,
            repositories: $repositories,
            permissions: $permissions,
        );
    }

    /**
     * A scope for reading an installation's own metadata: every repository, but
     * `metadata: read` and nothing else.
     *
     * There is one legitimate operation that cannot name a repository — asking WHICH
     * repositories an installation has. Answering it with a null scope would mint a
     * token carrying every permission the installation granted (`contents: write`
     * included) across the whole account, for an hour. This narrows the other axis
     * instead: as wide as it must be, as weak as it can be.
     */
    public static function metadataOnly(): self
    {
        return new self(permissions: ['metadata' => 'read'], installationWide: true);
    }

    /**
     * No repository selector AND no deliberate choice to go wide — i.e. a caller whose
     * repository list came back empty. A token minted from this would reach the whole
     * installation with every granted permission, so `TokenManager` refuses it.
     */
    public function isEmpty(): bool
    {
        return $this->repositoryIds === [] && $this->repositories === [] && ! $this->installationWide;
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
            // Numeric, because GitHub rejects string ids on this field. The constructor
            // has already proven every entry is digits.
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
     *
     * Digested over the ENCODED PAYLOAD with `JSON_THROW_ON_ERROR`. Without it,
     * `json_encode` returns `false` for input this DTO cannot rule out (invalid UTF-8 in
     * a repository name), `(string) false` is `''`, and every such scope collapses onto
     * one shared cache key — where the second caller is served a token minted for the
     * first caller's repository. A throw is the only safe answer: a scope whose identity
     * cannot be computed must not be cached under a guess.
     *
     * @throws \JsonException
     */
    public function digest(): string
    {
        $ids = array_map(intval(...), $this->repositoryIds);
        $names = $this->repositories;
        $permissions = $this->permissions;

        sort($ids);
        sort($names);
        ksort($permissions);

        return (new Digest)->hex(json_encode([$ids, $names, $permissions, $this->installationWide], JSON_THROW_ON_ERROR));
    }
}
