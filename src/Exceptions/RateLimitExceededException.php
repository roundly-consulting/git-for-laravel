<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Exceptions;

use Exception;

final class RateLimitExceededException extends Exception
{
    public static function for(string $provider, int $availableInSeconds): self
    {
        return new self(
            "Rate limit for provider [{$provider}] exceeded. Retry in {$availableInSeconds} second(s)."
        );
    }
}
