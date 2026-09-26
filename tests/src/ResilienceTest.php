<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as Psr7Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RoundlyConsulting\Git\Dto\Input\NewPullRequest;

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

it('does not retry a client error — a 404 answers the same every time', function () {
    config()->set('git.providers.github.retry', ['times' => 3, 'backoff' => 0]);

    Http::fakeSequence('*/repos/o/r')
        ->push(['message' => 'Not Found'], 404)
        ->push(snapshotData('github/repository'), 200);

    expect(fn () => github()->repository('o/r'))
        ->toThrow(fn (RequestException $exception) => expect($exception->response->status())->toBe(404));

    Http::assertSentCount(1);
});

it('never retries a write, even on a 5xx that may already have landed', function () {
    config()->set('git.providers.github.retry', ['times' => 3, 'backoff' => 0]);

    Http::fakeSequence('*/repos/o/r/pulls')
        ->push(['message' => 'Bad Gateway'], 502)
        ->push(['id' => 1, 'number' => 8, 'title' => 'Add CI', 'state' => 'open', 'head' => ['ref' => 'f'], 'base' => ['ref' => 'main']], 201);

    expect(fn () => github()->createPullRequest('o/r', new NewPullRequest('Add CI', 'f', 'main')))
        ->toThrow(fn (RequestException $exception) => expect($exception->response->status())->toBe(502));

    // A second POST would have opened a duplicate pull request.
    Http::assertSentCount(1);
});

it('retries a read on a 5xx and on a dropped connection', function () {
    config()->set('git.providers.github.retry', ['times' => 3, 'backoff' => 0]);

    $attempts = 0;

    Http::fake(['*/repos/o/r' => function () use (&$attempts) {
        $attempts++;

        return match ($attempts) {
            1 => throw new ConnectException('connection reset', new Psr7Request('GET', 'https://api.github.com/repos/o/r')),
            2 => Http::response(['message' => 'Service Unavailable'], 503),
            default => Http::response(snapshotData('github/repository')),
        };
    }]);

    expect(github()->repository('o/r')->name)->not->toBe('')
        ->and($attempts)->toBe(3);
});
