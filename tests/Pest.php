<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Facades\Registry;
use RoundlyConsulting\Git\Providers\Bitbucket;
use RoundlyConsulting\Git\Providers\Github;
use RoundlyConsulting\Git\Providers\Gitlab;
use RoundlyConsulting\Git\Tests\TestCase;
use RoundlyConsulting\Git\Tests\WebhookTestCase;

uses(WebhookTestCase::class)->in(__DIR__.'/src/Webhooks');
uses(TestCase::class)->in(
    __DIR__.'/src/Providers',
    __DIR__.'/src/Dto',
    __DIR__.'/src/Commands',
    __DIR__.'/src/Query',
    __DIR__.'/src/Testing',
    __DIR__.'/src/Mapping',
    __DIR__.'/src/Enums',
    __DIR__.'/src/Batch',
    __DIR__.'/src/Auth',
    __DIR__.'/src/EnumsTest.php',
    __DIR__.'/src/RegistryTest.php',
    __DIR__.'/src/RateLimitTest.php',
    __DIR__.'/src/ResilienceTest.php',
    __DIR__.'/src/WebhookRouteTest.php',
    __DIR__.'/src/CapabilityMatrixTest.php',
    __DIR__.'/src/AppAuthTest.php',
    __DIR__.'/ArchTest.php',
);

if (! function_exists('snapshot')) {
    function snapshot(string $name, bool $raw = false, int $times = 1): array|PromiseInterface
    {
        $response = file_get_contents(__DIR__.'/snapshots/'.$name.'.json');
        $response = json_decode($response, true);
        $result = [];

        if ($times > 1) {
            for ($i = 0; $i < $times; $i++) {
                $result[] = $response;
            }
        } else {
            $result = $response;
        }

        if ($raw) {
            return $result;
        }

        return Http::response($result);
    }
}

if (! function_exists('snapshotData')) {
    /** @return array<mixed> */
    function snapshotData(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/snapshots/'.$name.'.json'), true);
    }
}

if (! function_exists('github')) {
    function github(?string $accessToken = 'token-value'): Github
    {
        return Registry::github(
            new Token(
                credentials: new SensitiveParameterValue($accessToken)
            ),
        );
    }
}

if (! function_exists('gitlab')) {
    function gitlab(?string $accessToken = 'token-value'): Gitlab
    {
        return Registry::gitlab(
            new Token(
                credentials: new SensitiveParameterValue($accessToken)
            ),
        );
    }
}

if (! function_exists('bitbucket')) {
    function bitbucket(?string $accessToken = 'token-value'): Bitbucket
    {
        return Registry::bitbucket(
            new Token(
                credentials: new SensitiveParameterValue($accessToken)
            ),
        );
    }
}
