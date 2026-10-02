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

    /** @param  non-empty-list<string>  $filters */
    public static function commitFilters(array $filters, string $provider, string $supported): self
    {
        $named = implode(', ', $filters);

        return new self(
            "Provider [{$provider}] cannot filter commits by [{$named}]; its commits endpoint only takes {$supported}."
        );
    }
}
