<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Testing;

use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Interfaces\Provider;
use RoundlyConsulting\Git\Providers\Bitbucket;
use RoundlyConsulting\Git\Providers\Github;
use RoundlyConsulting\Git\Providers\Gitlab;
use RoundlyConsulting\Git\Registry;

final class RegistryFake extends Registry
{
    /** @var array<string, ProviderFake> */
    private array $providers = [];

    /** @var array<string, list<RecordedCall>> */
    private array $calls = [];

    public function __construct()
    {
        Http::preventStrayRequests();
    }

    public function github(?Credentials $credentials = null): ProviderFake
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

    public function provider(ProviderName|string $provider, ?Credentials $credentials = null): Provider
    {
        return $this->fakeFor($this->name($provider));
    }

    public function fakeFor(ProviderName $name): ProviderFake
    {
        return $this->providers[$name->key()] ??= new ProviderFake($name, $this);
    }

    public function record(ProviderName $provider, RecordedCall $call): void
    {
        $this->calls[$provider->key()][] = $call;
    }

    public function assertSent(ProviderName $provider, string $method): void
    {
        Assert::assertTrue(
            $this->wasSent($provider, $method),
            "Expected [{$method}] to be sent to [{$provider->label()}] but it was not."
        );
    }

    public function assertNotSent(ProviderName $provider, string $method): void
    {
        Assert::assertFalse(
            $this->wasSent($provider, $method),
            "Expected [{$method}] not to be sent to [{$provider->label()}] but it was."
        );
    }

    public function assertNothingSent(): void
    {
        Assert::assertSame([], $this->calls, 'Expected no provider calls, but some were recorded.');
    }

    public function assertRepositoryCreated(string $name): void
    {
        $created = collect($this->calls)
            ->flatten(1)
            ->contains(fn (RecordedCall $call): bool => $call->method === 'createRepository'
                && ($call->arguments[0]->name ?? null) === $name);

        Assert::assertTrue($created, "Expected repository [{$name}] to be created, but it was not.");
    }

    private function wasSent(ProviderName $provider, string $method): bool
    {
        foreach ($this->calls[$provider->key()] ?? [] as $call) {
            if ($call->method === $method) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  ProviderName|class-string<Provider>|string  $provider
     */
    private function name(ProviderName|string $provider): ProviderName
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
