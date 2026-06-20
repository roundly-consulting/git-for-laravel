<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\RateLimit;
use RoundlyConsulting\Git\Exceptions\RateLimitExceededException;

it('maps configured timespans to decay seconds', function (string $timespan, int $seconds) {
    $limit = new RateLimit(key: 'app', maxAttempts: 5, timespan: $timespan);

    expect($limit->decaySeconds())->toBe($seconds);
})->with([
    'second' => ['second', 1],
    'minute' => ['minute', 60],
    'hour' => ['hour', 3600],
    'day' => ['day', 86400],
    'numeric fallback' => ['120', 120],
]);

it('throws once the configured request budget is exceeded', function () {
    config()->set('git.providers.github.rateLimits', [
        'owner' => 'app',
        'maxAttempts' => 1,
        'timespan' => 'hour',
    ]);

    Http::fake([
        '/user' => snapshot('github/user'),
    ]);

    github()->user();

    github()->user();
})->throws(RateLimitExceededException::class);
