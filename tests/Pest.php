<?php

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Facades\Registry;
use RoundlyConsulting\Git\Providers\Bitbucket;
use RoundlyConsulting\Git\Providers\Github;
use RoundlyConsulting\Git\Providers\Gitlab;
use RoundlyConsulting\Git\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

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

if (! function_exists('github')) {
    function github(?string $accessToken = null): Github
    {
        return Registry::github(
            new Token(
                credentials: new SensitiveParameterValue($accessToken)
            ),
        );
    }
}

if (! function_exists('gitlab')) {
    function gitlab(?string $accessToken = null): Gitlab
    {
        return Registry::gitlab(
            new Token(
                credentials: new SensitiveParameterValue($accessToken)
            ),
        );
    }
}

if (! function_exists('bitbucket')) {
    function bitbucket(?string $accessToken = null): Bitbucket
    {
        return Registry::bitbucket(
            new Token(
                credentials: new SensitiveParameterValue($accessToken)
            ),
        );
    }
}
