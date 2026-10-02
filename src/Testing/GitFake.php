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
        return $this->fakeFor(ProviderName::Github);
    }

    public function githubApp(?GithubApp $credentials = null): ProviderFake
    {
        return $this->fakeFor(ProviderName::Github);
    }

    public function gitlab(?Credentials $credentials = null): ProviderFake
    {
        return $this->fakeFor(ProviderName::Gitlab);
    }

    public function bitbucket(?Credentials $credentials = null): ProviderFake
    {
        return $this->fakeFor(ProviderName::Bitbucket);
    }

    /**
     * @param  ProviderName|class-string<Provider>|string  $provider
     */
    public function provider(ProviderName|string $provider, ?Credentials $credentials = null): ProviderFake
    {
        return $this->fakeFor($this->resolveProviderName($provider));
    }

    /**
     * The one fake driver per provider — seed it before the code under test runs.
     *
     * It enforces the feature list of the real driver the container resolves for that
     * provider, so the fake supports exactly what production does.
     */
    public function fakeFor(ProviderName $name): ProviderFake
    {
        if (! isset($this->providers[$name->key()])) {
            /** @var Provider $real */
            $real = $this->container->make($name->providerClass());

            $this->providers[$name->key()] = new ProviderFake($name, $this, $real->features());
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
