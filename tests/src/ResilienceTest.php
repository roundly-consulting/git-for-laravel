<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

it('serves a 304 response from the etag cache without re-parsing fresh body', function () {
    config()->set('git.cache.enabled', true);

    $body = json_encode(['id' => 1, 'login' => 'octocat']);

    Http::fakeSequence('*/user')
        ->push((array) json_decode((string) $body, true), 200, ['ETag' => '"abc"'])
        ->push('', 304, ['ETag' => '"abc"']);

    expect(github()->user()->name)->toBe('octocat');

    // Second call returns 304; the cached body is replayed.
    expect(github()->user()->name)->toBe('octocat');

    Http::assertSentCount(2);
});

it('retries a 429 response and then succeeds', function () {
    config()->set('git.providers.github.retry', ['times' => 2, 'backoff' => 1]);

    Http::fakeSequence('*/user')
        ->push(['message' => 'rate limited'], 429)
        ->push(['id' => 1, 'login' => 'octocat'], 200);

    expect(github()->user()->name)->toBe('octocat');

    Http::assertSentCount(2);
});

it('logs request metadata without leaking the token', function () {
    config()->set('git.logging.enabled', true);

    Log::spy();

    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'octocat'])]);

    github('super-secret-token')->user();

    Log::shouldHaveReceived('debug')->withArgs(function (string $message, array $context): bool {
        return $message === 'git request'
            && $context['provider'] === 'github'
            && ! str_contains((string) json_encode($context), 'super-secret-token');
    });
});

it('does not log when logging is disabled', function () {
    Log::spy();

    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'octocat'])]);

    github()->user();

    Log::shouldNotHaveReceived('debug');
});
