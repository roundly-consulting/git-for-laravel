<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Exceptions;

use Exception;

final class FeatureNotSupportedException extends Exception
{
    public static function for(string $feature, string $provider): self
    {
        return new self(
            "Feature [{$feature}] is not supported by provider [{$provider}]."
        );
    }
}
