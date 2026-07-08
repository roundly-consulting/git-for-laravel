<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Exceptions\RateLimitExceededException;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;

it('records a hit through the rate limiter on an allowed request', function () {
    $limiter = RateLimits::fake();

    Http::fake(['*/user' => snapshot('github/user')]);

    github()->user();

    $limiter->assertAllowed('git:github:app');
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

    $limiter->assertDeferred('git:github:app');
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

    $limiter->assertDeferred('git:github:app');
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

    $limiter->assertDeferred('git:github:app')
        ->assertAllowed('git:gitlab:app');
});
