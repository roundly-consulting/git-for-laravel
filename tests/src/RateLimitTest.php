<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\RateLimit;
use RoundlyConsulting\Git\Enums\Timespan;
use RoundlyConsulting\Git\Exceptions\RateLimitExceededException;

it('maps timespan enums to decay seconds', function (Timespan $timespan, int $seconds) {
    $limit = new RateLimit(key: 'app', maxAttempts: 5, timespan: $timespan);

    expect($limit->decaySeconds())->toBe($seconds);
})->with([
    'second' => [Timespan::Second, 1],
    'minute' => [Timespan::Minute, 60],
    'hour' => [Timespan::Hour, 3600],
    'day' => [Timespan::Day, 86400],
]);

it('supports a custom integer decay window', function () {
    $limit = new RateLimit(key: 'app', maxAttempts: 5, timespan: 120);

    expect($limit->decaySeconds())->toBe(120);
});

it('throws once the configured request budget is exceeded', function () {
    config()->set('git.providers.github.rateLimits', [
        'owner' => 'app',
        'maxAttempts' => 1,
        'timespan' => 'hour',
    ]);

    Http::fake([
        '*/user' => snapshot('github/user'),
    ]);

    github()->user();

    github()->user();
})->throws(RateLimitExceededException::class);
