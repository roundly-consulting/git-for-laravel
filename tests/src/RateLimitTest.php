<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Crypto\Hash\Digest;
use RoundlyConsulting\Git\Dto\Input\InstallationTokenScope;
use RoundlyConsulting\Git\Exceptions\RateLimitExceededException;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\HttpClientRateLimits\Events\RequestAllowed;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\PackageToolkit\Contracts\HasRetryAfter;

it('records a hit through the rate limiter on an allowed request', function () {
    $limiter = RateLimits::fake();

    Http::fake(['*/user' => snapshot('github/user')]);

    github()->user();

    $limiter->assertAllowed(rateLimitKey('github'));
});

it('paces further requests once the window is exhausted', function () {
    config()->set('git.providers.github.rateLimits', [
        'owner' => 'app',
        'maxAttempts' => 1,
        'timespan' => 'hour',
    ]);

    $limiter = RateLimits::fake();

    Http::fake(['*/user' => snapshot('github/user')]);

    github()->user();
    github()->user();

    $limiter->assertDeferred(rateLimitKey('github'));
});

it('fails fast with the typed exception once the max wait is exceeded', function () {
    config()->set('git.providers.github.rateLimits', [
        'owner' => 'app',
        'maxAttempts' => 1,
        'timespan' => 'hour',
        'max_wait' => 0,
    ]);

    RateLimits::fake();

    Http::fake(['*/user' => snapshot('github/user')]);

    github()->user();

    github()->user();
})->throws(RateLimitExceededException::class);

it('sends without a limiter when throttling is disabled', function () {
    config()->set('git.providers.github.rateLimits', ['enabled' => false]);

    $limiter = RateLimits::fake();

    Http::fake(['*/user' => snapshot('github/user')]);

    expect(github()->user()->name)->toBe('octocat');

    github()->user();

    $limiter->assertNothingDeferred();
});

it('adapts to a provider Retry-After header and defers the next call', function () {
    config()->set('git.providers.github.rateLimits', [
        'owner' => 'app',
        'maxAttempts' => 5000,
        'timespan' => 'hour',
        'adaptive' => true,
    ]);

    $limiter = RateLimits::fake();

    Http::fakeSequence('*/user')
        ->push(snapshotData('github/user'), 200, ['Retry-After' => '2'])
        ->push(snapshotData('github/user'), 200);

    github()->user();
    github()->user();

    $limiter->assertDeferred(rateLimitKey('github'));
});

it('keeps a separate budget per provider', function () {
    config()->set('git.providers.github.rateLimits', ['owner' => 'app', 'maxAttempts' => 1, 'timespan' => 'hour']);
    config()->set('git.providers.gitlab.rateLimits', ['owner' => 'app', 'maxAttempts' => 1, 'timespan' => 'hour']);

    $limiter = RateLimits::fake();

    Http::fake([
        '*/api/v4/user' => snapshot('gitlab/user'),
        '*/user' => snapshot('github/user'),
    ]);

    github()->user();
    github()->user();
    gitlab()->user();

    $limiter->assertDeferred(rateLimitKey('github'))
        ->assertAllowed(rateLimitKey('gitlab'));
});

it('carries a retry-after hint on the typed exception', function () {
    config()->set('git.providers.github.rateLimits', [
        'owner' => 'app',
        'maxAttempts' => 1,
        'timespan' => 'hour',
        'max_wait' => 0,
    ]);

    RateLimits::fake();

    Http::fake(['*/user' => snapshot('github/user')]);

    github()->user();

    try {
        github()->user();
    } catch (RateLimitExceededException $exception) {
        expect($exception)->toBeInstanceOf(HasRetryAfter::class)
            ->and($exception->retryAfterSeconds())->toBeGreaterThan(0)
            ->and($exception->getMessage())->toContain('Rate limit for provider [github] exceeded');

        return;
    }

    $this->fail('The rate limiter did not fail fast.');
});

it('keeps a separate budget per credential, so one token running dry stalls no other', function () {
    config()->set('git.providers.github.rateLimits', [
        'owner' => 'app',
        'maxAttempts' => 5000,
        'timespan' => 'hour',
        'adaptive' => true,
        'max_wait' => 0,
    ]);

    RateLimits::fake();

    $keys = [];
    Event::listen(RequestAllowed::class, function (RequestAllowed $event) use (&$keys): void {
        $keys[] = $event->key;
    });

    // Token A's quota is spent; GitHub counts it per token, so token B's is untouched.
    Http::fake(function ($request) {
        $exhausted = $request->header('Authorization')[0] === 'Bearer token-a';

        return Http::response(snapshotData('github/user'), 200, [
            'X-RateLimit-Limit' => '5000',
            'X-RateLimit-Remaining' => $exhausted ? '0' : '4999',
            'X-RateLimit-Reset' => (string) now()->addHour()->getTimestamp(),
        ]);
    });

    github('token-a')->user();

    expect(github('token-b')->user()->name)->toBe('octocat')
        ->and(fn () => github('token-a')->user())->toThrow(RateLimitExceededException::class);

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->not->toBe($keys[1])
        ->and($keys)->each->toStartWith('git:github:app:')
        ->and(implode(' ', $keys))->not->toContain('token-a')->not->toContain('token-b');
});

it('throttles an unauthenticated provider under an anonymous budget', function () {
    $limiter = RateLimits::fake();

    Http::fake(['*/repos/o/r' => snapshot('github/repository')]);

    Git::github()->repository('o/r');

    $limiter->assertAllowed('git:github:app:anon');
});

it('shares one budget between the scoped mints of one installation, as GitHub counts them', function () {
    RateLimits::fake();

    $keys = [];
    Event::listen(RequestAllowed::class, function (RequestAllowed $event) use (&$keys): void {
        $keys[] = $event->key;
    });

    Http::fake([
        '*/app/installations/999/access_tokens' => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601String()], 201),
        '*/repos/o/r' => snapshot('github/repository'),
    ]);

    $wide = appCredentials();

    Git::github($wide)->repository('o/r');
    Git::github($wide->forScope(InstallationTokenScope::forRepositories(repositoryIds: ['1'])))->repository('o/r');

    expect($keys)->toBe(array_fill(0, 2, 'git:github:app:'.(new Digest)->hex('app:123:999')));
});
