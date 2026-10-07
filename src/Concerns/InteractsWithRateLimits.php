<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Concerns;

use Closure;
use Illuminate\Http\Client\Response;
use RoundlyConsulting\Git\Exceptions\RateLimitExceededException;
use RoundlyConsulting\Git\Support\Settings;
use RoundlyConsulting\HttpClientRateLimits\Enums\Timespan;
use RoundlyConsulting\HttpClientRateLimits\Exceptions\RateLimitExceededException as HttpRateLimitExceededException;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;
use RoundlyConsulting\HttpClientRateLimits\Limit;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;

trait InteractsWithRateLimits
{
    /**
     * What tells one caller's budget from another's — a digest, never a secret.
     *
     * The forges count their quota per token or installation, so two credentials sharing
     * one client-side window would also share its adaptive penalty: one installation's
     * exhausted quota would stall every other one.
     */
    abstract protected function rateLimitIdentity(): string;

    /**
     * Build the client-side rate limiter for a provider from its config, or
     * null when the host has disabled throttling for it.
     *
     * Requests are paced (the limiter waits until the window frees up) rather
     * than hard-failing; set a `max_wait` to fail fast instead. With `adaptive`
     * on (the default) the limiter also honours the provider's own
     * `Retry-After` / `X-RateLimit-*` headers.
     *
     * One budget per provider, owner and credential: `git:<provider>:<owner>:<identity>`.
     */
    protected function rateLimiter(string $provider): ?RateLimit
    {
        /** @var array<string, mixed> $config */
        $config = config("git.providers.{$provider}.rateLimits", []);
        $key = "git.providers.{$provider}.rateLimits";

        // Booleans and integers arrive from `.env` as strings (`0`, `off`, `5000`); they
        // are read as what they spell, never as a truthy string.
        if (! Settings::boolean("{$key}.enabled", $config['enabled'] ?? null, true)) {
            return null;
        }

        // A typo'd window, a junk max_wait / jitter or a blank owner throws naming its key —
        // never pacing per minute, or not at all, behind the host's back.
        $timespan = Settings::enum("{$key}.timespan", $config['timespan'] ?? null, Timespan::class, Timespan::Minute);
        $owner = Settings::string("{$key}.owner", $config['owner'] ?? null, 'app');
        $maxWait = Settings::optionalInteger("{$key}.max_wait", $config['max_wait'] ?? null, 0, PHP_INT_MAX);
        $jitter = Settings::optionalInteger("{$key}.jitter", $config['jitter'] ?? null, 0, PHP_INT_MAX);

        $rateLimit = RateLimits::make(new Limit(
            maxAttempts: Settings::integer("{$key}.maxAttempts", $config['maxAttempts'] ?? null, 1, PHP_INT_MAX, 60),
            timespan: $timespan,
        ))->by("git:{$provider}:{$owner}:{$this->rateLimitIdentity()}");

        if (Settings::boolean("{$key}.adaptive", $config['adaptive'] ?? null, true)) {
            $rateLimit->adaptive();
        }

        if ($maxWait !== null) {
            $rateLimit->maxWait($maxWait);
        }

        if ($jitter !== null) {
            $rateLimit->jitter($jitter);
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
                retryAfterSeconds: (int) ceil($exception->delayMs / 1000),
            );
        }
    }
}
