<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Credentials;

use SensitiveParameterValue;

/**
 * Factory for the simple secret-backed credential types (token / password /
 * private key). Lives on a trait so the self-refreshing credentials, whose
 * constructors take a different shape, are not bound to this signature.
 */
trait BuildsFromSecret
{
    public static function from(mixed $value): static
    {
        return new static(
            new SensitiveParameterValue($value),
        );
    }
}
