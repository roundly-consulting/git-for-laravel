<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Traits\Macroable;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Interfaces\Provider;
use RoundlyConsulting\Git\Providers\Bitbucket;
use RoundlyConsulting\Git\Providers\Github;
use RoundlyConsulting\Git\Providers\Gitlab;
use RoundlyConsulting\Git\Testing\RegistryFake;

class Registry
{
    use Macroable;

    public function github(?Credentials $credentials = null): Provider|Github
    {
        return $this->provider(ProviderName::Github, $credentials);
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
     * @param  ProviderName|class-string<Provider>|string  $provider
     */
    public function provider(ProviderName|string $provider, ?Credentials $credentials = null): Provider
    {
        $name = $this->resolveProviderName($provider);

        /** @var Provider $instance */
        $instance = resolve($name->providerClass());

        $credentials ??= $this->defaultCredentials($name);

        if (! $credentials) {
            return $instance;
        }

        return $instance->authenticate($credentials);
    }

    public function fake(): RegistryFake
    {
        $fake = new RegistryFake;

        app()->instance(self::class, $fake);
        Facade::clearResolvedInstance(self::class);

        return $fake;
    }

    protected function defaultCredentials(ProviderName $provider): ?Credentials
    {
        $token = config("git.providers.{$provider->key()}.token");

        if (! is_string($token) || $token === '') {
            return null;
        }

        return Token::from($token);
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
