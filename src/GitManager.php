<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git;

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Traits\Macroable;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Credentials\GithubApp;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Interfaces\Provider;
use RoundlyConsulting\Git\Providers\BaseProvider;
use RoundlyConsulting\Git\Providers\Bitbucket;
use RoundlyConsulting\Git\Providers\Github;
use RoundlyConsulting\Git\Providers\Gitlab;
use RoundlyConsulting\Git\Support\Settings;
use RoundlyConsulting\Git\Webhooks\SignatureVerifier;

/**
 * The root of the `Git` facade, and the injectable entry point for everyone who prefers
 * constructor injection: `public function __construct(private GitManager $git) {}`.
 *
 * It hands out one authenticated driver per call — `github()`, `gitlab()`, `bitbucket()`,
 * `provider()` — whose `repo()` and `installations()` handles carry the rest of the API.
 * Not final on purpose: `Testing\GitFake` extends it, so an injected manager and the
 * facade both see the fake once `Git::fake()` swapped it in.
 */
class GitManager
{
    use Macroable;

    public function __construct(
        protected readonly Container $container,
    ) {}

    public function github(?Credentials $credentials = null): Provider|Github
    {
        return $this->provider(ProviderName::Github, $credentials);
    }

    /**
     * The GitHub provider authenticated as the APP itself (its RS256 JWT), rather than
     * as one of its installations.
     *
     * This is what the `/app/**` endpoints need — chiefly looking an installation up to
     * verify it before trusting an id that arrived from a browser. Defaults to the
     * configured app; a deployment with no app configured fails loudly, naming the
     * missing key, rather than silently falling back to the static token.
     *
     * Typed like `github()` — `Provider|Github` — so an IDE completes the GitHub-only
     * surface on the return of the method whose entire purpose is GitHub App endpoints.
     */
    public function githubApp(?GithubApp $credentials = null): Provider|Github
    {
        return $this->provider(
            ProviderName::Github,
            $credentials ?? $this->appCredentials(ProviderName::Github),
        );
    }

    public function gitlab(?Credentials $credentials = null): Provider|Gitlab
    {
        return $this->provider(ProviderName::Gitlab, $credentials);
    }

    public function bitbucket(?Credentials $credentials = null): Provider|Bitbucket
    {
        return $this->provider(ProviderName::Bitbucket, $credentials);
    }

    /**
     * A driver by enum, driver class-string or config key, authenticated with the given
     * credential — or with the configured one ({@see Credentials()}) when none is given.
     *
     * @param  ProviderName|class-string<Provider>|string  $provider
     */
    public function provider(ProviderName|string $provider, ?Credentials $credentials = null): Provider
    {
        $name = $this->resolveProviderName($provider);

        /** @var Provider $instance */
        $instance = $this->container->make($name->providerClass());

        $credentials ??= $this->credentials($name);

        if (! $credentials) {
            return $instance;
        }

        return $instance->authenticate($credentials);
    }

    /**
     * The capability matrix for a provider without authenticating it.
     *
     * @param  ProviderName|class-string<Provider>|string  $provider
     * @return array<string, bool>
     */
    public function capabilities(ProviderName|string $provider): array
    {
        $name = $this->resolveProviderName($provider);

        /** @var BaseProvider $instance */
        $instance = $this->container->make($name->providerClass());

        return $instance->capabilities();
    }

    /**
     * The credential a provider gets when the caller passes none — the configured GitHub
     * App installation (`app.id` + `app.installation_id` + `app.private_key`) first, then
     * the static `token`, else `null` (an unauthenticated provider).
     *
     * Public so a host can hand the SAME credential to something outside this package —
     * a `git clone` subprocess, say, via `cloneUrl()` — or read its live access token:
     * a refreshable one answers `accessToken()`, minting and caching as needed.
     *
     * @param  ProviderName|class-string<Provider>|string  $provider
     */
    public function credentials(ProviderName|string $provider): ?Credentials
    {
        $key = $this->resolveProviderName($provider)->key();

        $appId = config("git.providers.{$key}.app.id");

        if (is_string($appId) && $appId !== '') {
            $installationId = config("git.providers.{$key}.app.installation_id");
            $privateKey = config("git.providers.{$key}.app.private_key");

            if (is_string($installationId) && $installationId !== '' && is_string($privateKey) && $privateKey !== '') {
                return GithubAppToken::for(
                    appId: $appId,
                    installationId: $installationId,
                    privateKey: $privateKey,
                    apiBaseUrl: Settings::optionalString("git.providers.{$key}.url", config("git.providers.{$key}.url")),
                );
            }
        }

        $token = config("git.providers.{$key}.token");

        if (! is_string($token) || $token === '') {
            return null;
        }

        return Token::from($token);
    }

    /**
     * Whether an inbound webhook really came from the provider — the check the package's
     * own webhook route runs, for a host that owns its route instead.
     *
     * Verified against `git.providers.<key>.webhook_secret`; no configured secret means
     * nothing verifies (`false`), never "everything does".
     *
     * @param  ProviderName|class-string<Provider>|string  $provider
     */
    public function verifyWebhook(ProviderName|string $provider, Request $request): bool
    {
        return $this->container->make(SignatureVerifier::class)
            ->verify($this->resolveProviderName($provider), $request);
    }

    /**
     * The configured app credentials for a provider.
     *
     * Throws rather than returning null on a missing key: an unauthenticated provider
     * would fail later with the generic "requires authentication" message, discarding the
     * one thing an operator needs — WHICH key is absent.
     *
     * @throws InvalidCredentialsException when the provider ships no app credentials
     */
    protected function appCredentials(ProviderName $provider): GithubApp
    {
        $key = $provider->key();

        $appId = config("git.providers.{$key}.app.id");
        $privateKey = config("git.providers.{$key}.app.private_key");

        if (! is_string($appId) || $appId === '') {
            throw InvalidCredentialsException::missingAppConfig($provider->key(), 'id');
        }

        if (! is_string($privateKey) || $privateKey === '') {
            throw InvalidCredentialsException::missingAppConfig($provider->key(), 'private_key');
        }

        return GithubApp::for(
            appId: $appId,
            privateKey: $privateKey,
            apiBaseUrl: Settings::optionalString("git.providers.{$key}.url", config("git.providers.{$key}.url")),
        );
    }

    /**
     * @param  ProviderName|class-string<Provider>|string  $provider
     */
    protected function resolveProviderName(ProviderName|string $provider): ProviderName
    {
        if ($provider instanceof ProviderName) {
            return $provider;
        }

        return match ($provider) {
            Github::class => ProviderName::Github,
            Gitlab::class => ProviderName::Gitlab,
            Bitbucket::class => ProviderName::Bitbucket,
            default => ProviderName::from($provider),
        };
    }
}
