<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use InvalidArgumentException;

final readonly class NewRepository
{
    public function __construct(
        public string $name,
        public bool $private = false,
        public ?string $description = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Repository name must not be empty.');
        }
    }
}
