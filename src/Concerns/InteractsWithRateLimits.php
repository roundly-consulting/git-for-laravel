<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Concerns;

use Closure;
use Illuminate\Http\Client\Response;
use RoundlyConsulting\Git\Exceptions\RateLimitExceededException;
use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\RateLimitExceededException as HttpRateLimitExceededException;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;

trait InteractsWithRateLimits
{
    /**
     * Build the client-side rate limiter for a provider from its config, or
     * null when the host has disabled throttling for it.
     *
     * Requests are paced (the limiter waits until the window frees up) rather
     * than hard-failing; set a `max_wait` to fail fast instead. With `adaptive`
     * on (the default) the limiter also honours the provider's own
     * `Retry-After` / `X-RateLimit-*` headers.
     */
    protected function rateLimiter(string $provider): ?RateLimit
    {
        /** @var array<string, mixed> $config */
        $config = config("git.providers.{$provider}.rateLimits", []);

        if (($config['enabled'] ?? true) === false) {
            return null;
        }

        $timespan = Timespan::tryFrom((string) ($config['timespan'] ?? 'minute')) ?? Timespan::Minute;
        $owner = (string) ($config['owner'] ?? 'app');

        $rateLimit = RateLimit::make(new Limit(
            maxAttempts: (int) ($config['maxAttempts'] ?? 60),
            timespan: $timespan,
        ))->by("git:{$provider}:{$owner}");

        if (($config['adaptive'] ?? true) === true) {
            $rateLimit->adaptive();
        }

        if (isset($config['max_wait']) && is_numeric($config['max_wait'])) {
            $rateLimit->maxWait((int) $config['max_wait']);
        }

        if (isset($config['jitter']) && is_numeric($config['jitter'])) {
            $rateLimit->jitter((int) $config['jitter']);
        }

        return $rateLimit;
    }

    /**
     * Send an HTTP request through the provider's rate limiter, translating the
     * limiter's own exhaustion exception into git's public typed exception so
     * downstream `catch (RateLimitExceededException)` keeps working.
     *
     * @param  Closure(): Response  $send
     */
    protected function throttled(string $provider, Closure $send): Response
    {
        $rateLimit = $this->rateLimiter($provider);

        if ($rateLimit === null) {
            return $send();
        }

        try {
            /** @var Response $response */
            $response = $rateLimit->handle($send);

            return $response;
        } catch (HttpRateLimitExceededException $exception) {
            throw RateLimitExceededException::for(
                provider: $provider,
                availableInSeconds: (int) ceil($exception->delayMs / 1000),
            );
        }
    }
}
