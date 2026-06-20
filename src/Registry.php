<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git;

use Illuminate\Support\Traits\Macroable;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Interfaces\Provider;
use RoundlyConsulting\Git\Providers\Bitbucket;
use RoundlyConsulting\Git\Providers\Github;
use RoundlyConsulting\Git\Providers\Gitlab;

class Registry
{
    use Macroable;

    public function github(?Credentials $credentials = null): Provider|Github
    {
        return $this->provider(Github::class, $credentials);
    }

    public function gitlab(?Credentials $credentials = null): Provider|Gitlab
    {
        return $this->provider(Gitlab::class, $credentials);
    }

    public function bitbucket(?Credentials $credentials = null): Provider|Bitbucket
    {
        return $this->provider(Bitbucket::class, $credentials);
    }

    public function provider(string $provider, ?Credentials $credentials = null): Provider
    {
        /** @var Provider $instance */
        $instance = resolve($provider);

        if (! $credentials) {
            return $instance;
        }

        return $instance->authenticate($credentials);
    }
}
