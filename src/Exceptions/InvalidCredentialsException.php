<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Exceptions;

use Exception;
use Throwable;

final class InvalidCredentialsException extends Exception
{
    public static function missing(string $provider, ?Throwable $previous = null): self
    {
        return new self(
            "Provider [{$provider}] requires authentication for this operation. Provide a credential or set the provider token in config.",
            previous: $previous,
        );
    }

    /**
     * The forge answered `401` to a request that carried a credential: the token is
     * wrong, revoked, or expired. Never names the token — the forge's own response is the
     * previous exception, for a log.
     */
    public static function rejected(string $provider, ?Throwable $previous = null): self
    {
        return new self(
            "Provider [{$provider}] rejected the credential (401): it is invalid, revoked, or expired.",
            previous: $previous,
        );
    }

    public static function invalidKey(string $reason = 'The supplied private key could not be read.'): self
    {
        return new self("Invalid GitHub App private key: {$reason}");
    }

    /**
     * A GitHub App installation that cannot mint: gone, suspended, or asked for a
     * scope it does not cover. Deliberately the same exception type as every other
     * credential failure — a consumer flags the connection and moves on either way.
     */
    public static function installationUnavailable(string $installationId, string $reason): self
    {
        return new self("GitHub App installation [{$installationId}] cannot issue a token: {$reason}");
    }

    /** A repository id that is not a GitHub numeric id, caught before it becomes `0`. */
    public static function invalidRepositorySelector(string $id): self
    {
        return new self(
            "A GitHub repository id must be numeric; received [{$id}]. Use the `repositories` ".
            'selector for a name, or the numeric id for `repositoryIds`.'
        );
    }

    /** An app or installation id that is not a GitHub numeric id (and would poison a cache key). */
    public static function invalidAppIdentifier(string $label, string $value): self
    {
        return new self("A GitHub App {$label} must be numeric; received [{$value}].");
    }

    /** A scope that names no repository at all — which would mint an installation-wide token. */
    public static function unscopedInstallationToken(): self
    {
        return new self(
            'This installation token scope names no repository, which would mint a token for EVERY '.
            'repository the installation can reach. Name the repositories, or pass no scope at all '.
            'when a connection-wide token is genuinely intended.'
        );
    }

    /**
     * A permission the app was never granted, told as this package's own failure.
     *
     * GitHub answers "Resource not accessible by integration" for it — a 403 whose body
     * names neither the permission nor the account, and which would otherwise reach the
     * caller verbatim inside a `RequestException` message. Creating a repository in an
     * organization is the case that provoked this: `administration: write` is approved by
     * an ORG OWNER on the existing installation, on a different axis from the repository
     * permissions the app normally uses, so the app can be perfectly healthy and still
     * unable to make this one call. That is a human action, which is what this exception
     * type means everywhere else in the package.
     *
     * Only raised once a rate limit has been ruled out: GitHub answers 403 for a secondary
     * rate limit too, and reporting a throttled minute as a broken connection would make a
     * consumer tear down a credential that was fine.
     */
    public static function missingPermission(string $provider, string $permission, string $operation): self
    {
        return new self(
            "The [{$provider}] app is not permitted to {$operation}. Grant it [{$permission}] ".
            'on the installation — an organization owner approves it — and try again.'
        );
    }

    /**
     * The credential type an endpoint requires. GitHub authenticates `/app/**` as the app
     * itself and everything else as an installation, and the two are not interchangeable:
     * the wrong one answers with GitHub's own opaque 403.
     *
     * @param  class-string  $required
     */
    public static function wrongCredentialType(string $provider, string $required, ?string $given): self
    {
        $given = $given === null ? 'none' : class_basename($given);

        return new self(
            "This [{$provider}] operation requires [".class_basename($required).'] credentials, '.
            "but [{$given}] was supplied."
        );
    }

    /**
     * The app half of a provider's config is incomplete. Deliberately says WHICH key is
     * missing: this exception is for an operator reading a log, and every consumer of
     * this package is expected to answer a user with one generic message instead.
     *
     * `$provider` is the CONFIG key (`github`), matching {@see missingOauthConfig()}.
     */
    public static function missingAppConfig(string $provider, string $key): self
    {
        return new self(
            "Provider [{$provider}] has no GitHub App {$key} configured. ".
            "Set [git.providers.{$provider}.app.{$key}]."
        );
    }

    public static function missingOauthConfig(string $provider, string $key): self
    {
        return new self(
            "Provider [{$provider}] has no OAuth {$key} configured. Set [git.providers.{$provider}.oauth.{$key}], ".
            'or pass the value explicitly to OauthToken::for().'
        );
    }

    /** @param list<class-string> $supported */
    public static function unsupported(string $provider, string $credentials, array $supported): self
    {
        $credentials = class_basename($credentials);
        $supported = collect($supported)->map(fn (string $className): string => class_basename($className))->implode(', ');

        return new self(
            "Authentication with [{$credentials}] is not supported by provider [{$provider}]. ".
            "Supported authentication methods are [{$supported}]."
        );
    }
}
