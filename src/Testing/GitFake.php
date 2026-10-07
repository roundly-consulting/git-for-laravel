<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Testing;

use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Credentials\GithubApp;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\GitManager;
use RoundlyConsulting\Git\Interfaces\Provider;

/**
 * The manager `Git::fake()` swaps in: every driver it hands out is a {@see ProviderFake},
 * no HTTP leaves the process, and every provider call — flat, through a `repo()` /
 * `pullRequest()` / `installations()` handle, `webhooks()` or `batch()` — is recorded
 * for the `assert*` methods below.
 *
 * A subtype of the manager on purpose: code that constructor-injects `GitManager` gets
 * this instance too, rather than a `TypeError`.
 */
final class GitFake extends GitManager
{
    /** @var array<string, ProviderFake> */
    private array $providers = [];

    /** @var array<string, list<RecordedCall>> */
    private array $calls = [];

    public function __construct(Container $container)
    {
        parent::__construct($container);

        Http::preventStrayRequests();
    }

    public function github(?Credentials $credentials = null): ProviderFake
    {
        return $this->provider(ProviderName::Github, $credentials);
    }

    /**
     * The fake GitHub driver authenticated as the app — resolving the configured app
     * credentials exactly as production does, so an unconfigured app throws
     * `InvalidCredentialsException::missingAppConfig()` here too. A host test configures
     * fake app keys (`git.providers.github.app.id` + `app.private_key`) or passes a
     * `GithubApp` of its own; nothing signs or sends with them.
     */
    public function githubApp(?GithubApp $credentials = null): ProviderFake
    {
        return $this->provider(ProviderName::Github, $credentials ?? $this->appCredentials(ProviderName::Github));
    }

    public function gitlab(?Credentials $credentials = null): ProviderFake
    {
        return $this->provider(ProviderName::Gitlab, $credentials);
    }

    public function bitbucket(?Credentials $credentials = null): ProviderFake
    {
        return $this->provider(ProviderName::Bitbucket, $credentials);
    }

    /**
     * A fake driver authenticated as the real manager would authenticate it: with the
     * credential passed, else the configured one ({@see Credentials()}), else none. Each
     * call gets its own copy — so one call's credential never leaks into another's — that
     * shares the provider's seeds and records.
     *
     * @param  ProviderName|class-string<Provider>|string  $provider
     *
     * @throws InvalidCredentialsException when the real driver does not take the credential's type
     */
    public function provider(ProviderName|string $provider, ?Credentials $credentials = null): ProviderFake
    {
        $name = $this->resolveProviderName($provider);
        $driver = $this->fakeFor($name)->fresh();

        $credentials ??= $this->credentials($name);

        return $credentials === null ? $driver : $driver->authenticate($credentials);
    }

    /**
     * The one fake driver per provider — seed it before the code under test runs. It is
     * unauthenticated; the drivers `github()`, `provider()` and friends hand out share its
     * seeds and carry their own credential.
     *
     * It enforces the feature list, the input checks and the credential types of the real
     * driver the container resolves for that provider, so the fake supports exactly what
     * production does.
     */
    public function fakeFor(ProviderName $name): ProviderFake
    {
        if (! isset($this->providers[$name->key()])) {
            /** @var Provider $real */
            $real = $this->container->make($name->providerClass());

            $this->providers[$name->key()] = new ProviderFake($name, $this, $real->features(), $real);
        }

        return $this->providers[$name->key()];
    }

    /**
     * @internal the fake drivers report every call here.
     */
    public function record(ProviderName $provider, RecordedCall $call): void
    {
        $this->calls[$provider->key()][] = $call;
    }

    /**
     * Every recorded call to a provider, oldest first — optionally only one method's.
     *
     * @return list<RecordedCall>
     */
    public function recorded(ProviderName $provider, ?string $method = null): array
    {
        return array_values(array_filter(
            $this->calls[$provider->key()] ?? [],
            static fn (RecordedCall $call): bool => $method === null || $call->method === $method,
        ));
    }

    /**
     * A provider method was called — optionally with arguments the callback accepts.
     *
     * The callback receives the call's arguments positionally, exactly as the provider
     * method took them: `fn (string $path, int $number): bool => $number === 12`.
     *
     * @param  (Closure(mixed ...): bool)|null  $callback
     */
    public function assertSent(ProviderName $provider, string $method, ?Closure $callback = null): void
    {
        Assert::assertTrue(
            $this->matching($provider, $method, $callback) !== [],
            "Expected [{$method}] to be sent to [{$provider->label()}] but it was not."
        );
    }

    public function assertSentTimes(ProviderName $provider, string $method, int $times): void
    {
        $count = count($this->recorded($provider, $method));

        Assert::assertSame(
            $times,
            $count,
            "Expected [{$method}] to be sent to [{$provider->label()}] {$times} time(s), but it was sent {$count} time(s)."
        );
    }

    /**
     * @param  (Closure(mixed ...): bool)|null  $callback
     */
    public function assertNotSent(ProviderName $provider, string $method, ?Closure $callback = null): void
    {
        Assert::assertSame(
            [],
            $this->matching($provider, $method, $callback),
            "Expected [{$method}] not to be sent to [{$provider->label()}] but it was."
        );
    }

    /** No provider call at all — or none to one provider. */
    public function assertNothingSent(?ProviderName $provider = null): void
    {
        if ($provider !== null) {
            Assert::assertSame([], $this->recorded($provider), "Expected no calls to [{$provider->label()}], but some were recorded.");

            return;
        }

        Assert::assertSame([], $this->calls, 'Expected no provider calls, but some were recorded.');
    }

    public function assertBatched(ProviderName $provider, string $method): void
    {
        Assert::assertTrue(
            $this->recorded($provider, "batch.{$method}") !== [],
            "Expected batch [{$method}] to be sent to [{$provider->label()}] but it was not."
        );
    }

    public function assertNotBatched(ProviderName $provider, string $method): void
    {
        Assert::assertSame(
            [],
            $this->recorded($provider, "batch.{$method}"),
            "Expected batch [{$method}] not to be sent to [{$provider->label()}] but it was."
        );
    }

    /**
     * A repository was created — optionally under a given owner, optionally from a given
     * template.
     *
     * `owner` and `template` are opt-in narrowings: "created" and "created in the right
     * organization, from the right template" are different claims, and only the second is
     * what a provisioning caller means.
     */
    public function assertRepositoryCreated(string $name, ?string $owner = null, ?string $template = null): void
    {
        $created = collect($this->calls)
            ->flatten(1)
            ->contains(fn (RecordedCall $call): bool => $call->method === 'createRepository'
                && ($call->arguments[0]->name ?? null) === $name
                && ($owner === null || ($call->arguments[0]->owner ?? null) === $owner)
                && ($template === null || ($call->arguments[0]->template ?? null) === $template));

        $detail = $owner !== null ? " under [{$owner}]" : '';
        $detail .= $template !== null ? " from template [{$template}]" : '';

        Assert::assertTrue($created, "Expected repository [{$name}]{$detail} to be created, but it was not.");
    }

    public function assertNoRepositoryCreated(): void
    {
        $created = collect($this->calls)
            ->flatten(1)
            ->contains(fn (RecordedCall $call): bool => $call->method === 'createRepository');

        Assert::assertFalse($created, 'Expected no repository to be created, but one was.');
    }

    /**
     * @param  (Closure(mixed ...): bool)|null  $callback
     * @return list<RecordedCall>
     */
    private function matching(ProviderName $provider, string $method, ?Closure $callback): array
    {
        return array_values(array_filter(
            $this->recorded($provider, $method),
            static fn (RecordedCall $call): bool => $callback === null || $callback(...$call->arguments) === true,
        ));
    }
}
