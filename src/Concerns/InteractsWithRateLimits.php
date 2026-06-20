<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Concerns;

use Illuminate\Support\Facades\RateLimiter;
use RoundlyConsulting\Git\Dto\RateLimit;
use RoundlyConsulting\Git\Enums\Timespan;
use RoundlyConsulting\Git\Exceptions\RateLimitExceededException;

trait InteractsWithRateLimits
{
    /**
     * Block the current call when the configured client-side limit is hit.
     *
     * Throttling is enforced with Laravel's native rate limiter so host apps
     * stay within the upstream provider's quota without a third-party package.
     */
    protected function enforceRateLimit(string $provider, RateLimit $limit): void
    {
        $key = "git:{$provider}:{$limit->key}";

        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            throw RateLimitExceededException::for(
                provider: $provider,
                availableInSeconds: RateLimiter::availableIn($key),
            );
        }

        RateLimiter::hit($key, $limit->decaySeconds());
    }

    protected function rateLimitFromConfig(string $provider): RateLimit
    {
        /** @var array{owner?: string, maxAttempts?: int|string, timespan?: string} $config */
        $config = config("git.providers.{$provider}.rateLimits", []);

        $timespan = (string) ($config['timespan'] ?? 'minute');

        return new RateLimit(
            key: $config['owner'] ?? 'app',
            maxAttempts: (int) ($config['maxAttempts'] ?? 60),
            timespan: Timespan::tryFrom($timespan) ?? (is_numeric($timespan) ? (int) $timespan : Timespan::Minute),
        );
    }
}
