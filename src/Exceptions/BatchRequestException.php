<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Exceptions;

use Exception;
use RoundlyConsulting\Git\Batch\BatchError;

final class BatchRequestException extends Exception
{
    /**
     * @param  array<string, BatchError>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        $keys = implode(', ', array_keys($errors));

        parent::__construct("Batch request failed for keys: [{$keys}].");
    }
}
