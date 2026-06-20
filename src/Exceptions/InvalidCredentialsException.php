<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Exceptions;

use Exception;

final class InvalidCredentialsException extends Exception
{
    public static function missing(string $provider): self
    {
        return new self(
            "Provider [{$provider}] requires authentication for this operation. Provide a credential or set the provider token in config."
        );
    }

    public static function invalidKey(string $reason = 'The supplied private key could not be read.'): self
    {
        return new self("Invalid GitHub App private key: {$reason}");
    }

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
