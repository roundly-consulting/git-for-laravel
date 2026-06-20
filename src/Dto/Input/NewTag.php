<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use InvalidArgumentException;

final readonly class NewTag
{
    public function __construct(
        public string $name,
        public string $ref,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Tag name must not be empty.');
        }

        if (trim($ref) === '') {
            throw new InvalidArgumentException('Tag ref must not be empty.');
        }
    }
}
