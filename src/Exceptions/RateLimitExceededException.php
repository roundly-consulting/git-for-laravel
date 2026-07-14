<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Exceptions;

use Exception;
use RoundlyConsulting\PackageToolkit\Concerns\ProvidesRetryAfter;
use RoundlyConsulting\PackageToolkit\Contracts\HasRetryAfter;

final class RateLimitExceededException extends Exception implements HasRetryAfter
{
    use ProvidesRetryAfter;

    public static function for(string $provider, int $retryAfterSeconds): self
    {
        $exception = new self(
            "Rate limit for provider [{$provider}] exceeded. Retry in {$retryAfterSeconds} second(s)."
        );

        return $exception->withRetryAfter($retryAfterSeconds);
    }
}
