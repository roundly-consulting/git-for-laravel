<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git;

use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Traits\Macroable;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Interfaces\Provider;
use RoundlyConsulting\Git\Providers\BaseProvider;
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

    /**
     * The capability matrix for a provider without authenticating it.
     *
     * @return array<string, bool>
     */
    public function capabilities(ProviderName|string $provider): array
    {
        $name = $this->resolveProviderName($provider);

        /** @var BaseProvider $instance */
        $instance = resolve($name->providerClass());

        return $instance->capabilities();
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
        $key = $provider->key();

        $appId = config("git.providers.{$key}.app.id");

        if (is_string($appId) && $appId !== '') {
            $installationId = config("git.providers.{$key}.app.installation_id");
            $privateKey = config("git.providers.{$key}.app.private_key");

            if (is_string($installationId) && $installationId !== '' && is_string($privateKey) && $privateKey !== '') {
                return GithubAppToken::for(
                    appId: $appId,
                    installationId: $installationId,
                    privateKey: $privateKey,
                    apiBaseUrl: is_string($url = config("git.providers.{$key}.url")) ? $url : null,
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
