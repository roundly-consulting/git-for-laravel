<?php

declare(strict_types=1);

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RoundlyConsulting\Git\Exceptions\RateLimitExceededException;
use RoundlyConsulting\Git\Providers\Github;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
 * `env()` hands every value over as a STRING (`GITHUB_TIMEOUT=45` is `"45"`), and only
 * `true`/`false` are converted for you. Every setting a host would put in `.env` has to
 * mean the same thing as a string as it does as a PHP literal.
 */

it('honours a numeric-string timeout', function (): void {
    config()->set('git.providers.github.timeout', '45');

    $timeouts = [];
    Http::fake(function ($request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response(['id' => 1, 'login' => 'octocat']);
    });

    github()->user();

    expect($timeouts)->toBe([45]);
});

it('honours numeric-string retry settings', function (): void {
    config()->set('git.providers.github.retry', ['times' => '3', 'backoff' => '1']);

    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;

        return Http::response(['message' => 'boom'], 503);
    });

    expect(fn () => github()->user())->toThrow(RequestException::class)
        ->and($attempts)->toBe(3);
});

it('honours a numeric-string batch concurrency', function (): void {
    config()->set('git.batch.concurrency', '2');

    $provider = new class extends Github
    {
        public function concurrency(): int
        {
            return $this->batchConcurrency();
        }
    };

    expect($provider->concurrency())->toBe(2);
});

it('refuses a malformed integer loudly instead of silently using the default', function (string $key, mixed $value): void {
    config()->set($key, $value);
    Http::fake();

    github()->user();
})->with([
    'timeout' => ['git.providers.github.timeout', 'ten'],
    'retry times' => ['git.providers.github.retry.times', '1.5'],
    'negative backoff' => ['git.providers.github.retry.backoff', '-1'],
])->throws(InvalidConfigurationException::class);

it('reads an env-string boolean the way a human wrote it', function (string $off): void {
    config()->set('git.providers.github.rateLimits', [
        'enabled' => $off, 'owner' => 'app', 'maxAttempts' => 1, 'timespan' => 'hour', 'max_wait' => 0,
    ]);
    RateLimits::fake();
    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'octocat'])]);

    github()->user();

    expect(github()->user()->name)->toBe('octocat');
})->with(['0', 'off', 'no', 'false']);

it('still limits when the flag is an env-string yes', function (): void {
    config()->set('git.providers.github.rateLimits', [
        'enabled' => 'yes', 'owner' => 'app', 'maxAttempts' => 1, 'timespan' => 'hour', 'max_wait' => 0,
    ]);
    RateLimits::fake();
    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'octocat'])]);

    github()->user();
    github()->user();
})->throws(RateLimitExceededException::class);

it('keeps logging and the etag cache off for an env-string off', function (): void {
    config()->set('git.logging.enabled', 'off');
    config()->set('git.cache.enabled', '0');
    Log::spy();

    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'octocat'], 200, ['ETag' => '"abc"'])]);

    github()->user();
    github()->user();

    Log::shouldNotHaveReceived('debug');
    Http::assertNotSent(fn ($request): bool => $request->hasHeader('If-None-Match'));
});

it('turns logging on for an env-string on', function (): void {
    config()->set('git.logging.enabled', 'on');
    Log::spy();
    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'octocat'])]);

    github()->user();

    Log::shouldHaveReceived('debug')->once();
});

it('ships the webhook route flag as a strict boolean for any env spelling', function (string $value, bool $expected): void {
    $_SERVER['GIT_WEBHOOKS_ENABLED'] = $_ENV['GIT_WEBHOOKS_ENABLED'] = $value;

    try {
        $config = require __DIR__.'/../../../config/git.php';
    } finally {
        unset($_SERVER['GIT_WEBHOOKS_ENABLED'], $_ENV['GIT_WEBHOOKS_ENABLED']);
    }

    expect($config['webhooks']['enabled'])->toBe($expected);
})->with([
    ['off', false],
    ['no', false],
    ['0', false],
    ['on', true],
    ['yes', true],
    ['1', true],
]);
