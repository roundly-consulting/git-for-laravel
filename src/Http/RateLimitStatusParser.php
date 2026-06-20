<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Http;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Dto\RateLimitStatus;

final class RateLimitStatusParser
{
    public static function fromResponse(Response $response): ?RateLimitStatus
    {
        // GitHub / Bitbucket use X-RateLimit-*; GitLab uses RateLimit-*.
        $limit = self::header($response, ['X-RateLimit-Limit', 'RateLimit-Limit']);
        $remaining = self::header($response, ['X-RateLimit-Remaining', 'RateLimit-Remaining']);

        if ($limit === null && $remaining === null) {
            return null;
        }

        $reset = self::header($response, ['X-RateLimit-Reset', 'RateLimit-Reset']);
        $used = self::header($response, ['X-RateLimit-Used', 'RateLimit-Used']);

        $limitValue = $limit !== null ? (int) $limit : 0;
        $remainingValue = $remaining !== null ? (int) $remaining : 0;

        return new RateLimitStatus(
            limit: $limitValue,
            remaining: $remainingValue,
            used: $used !== null ? (int) $used : max($limitValue - $remainingValue, 0),
            resetAt: $reset !== null ? Carbon::createFromTimestamp((int) $reset) : null,
        );
    }

    /** @param list<string> $names */
    private static function header(Response $response, array $names): ?string
    {
        foreach ($names as $name) {
            $value = $response->header($name);

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }
}
