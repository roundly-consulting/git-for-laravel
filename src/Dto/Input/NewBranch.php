<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use InvalidArgumentException;

final readonly class NewBranch
{
    public function __construct(
        public string $name,
        public string $fromRef,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Branch name must not be empty.');
        }

        if (trim($fromRef) === '') {
            throw new InvalidArgumentException('Base ref must not be empty.');
        }
    }
}
