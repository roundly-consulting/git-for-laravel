<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Exceptions;

use Exception;

final class InvalidCredentialsException extends Exception
{
    /** @param list<class-string> $supported */
    public static function unsupported(string $provider, string $credentials, array $supported): self
    {
        $credentials = class_basename($credentials);
        $supported = collect($supported)->map(fn (string $className): string => class_basename($className))->implode(', ');

        return new self(
            "Authentication with [{$credentials}] is not supported by provider [{$provider}].".
            "Supported authentication methods are [{$supported}]."
        );
    }
}
