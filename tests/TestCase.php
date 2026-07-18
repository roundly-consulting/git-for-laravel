<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Tests;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Crypto\CryptoServiceProvider;
use RoundlyConsulting\Git\GitServiceProvider;
use RoundlyConsulting\HttpClientRateLimits\HttpClientRateLimitsServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Every provider in this suite is driven through a faked HTTP client; a stray
        // request means a test is reaching a real forge rather than a snapshot.
        Http::preventStrayRequests();
    }

    /**
     * Every provider git hard-requires, in registration order — the two roundly
     * providers a host auto-discovers first, then git itself.
     *
     * CryptoServiceProvider was missing before this row, and it is not decoration:
     * `Auth\GithubAppJwt` and `Webhooks\SignatureVerifier` both resolve crypto out of
     * the container, so a suite that never registered it was testing an environment no
     * host ever runs.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function packageProviders(): array
    {
        return [
            CryptoServiceProvider::class,
            HttpClientRateLimitsServiceProvider::class,
            GitServiceProvider::class,
        ];
    }
}
