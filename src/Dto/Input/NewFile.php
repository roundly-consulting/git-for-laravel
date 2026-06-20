<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use InvalidArgumentException;

final readonly class NewFile
{
    public function __construct(
        public string $path,
        public string $content,
        public string $message,
        public string $branch,
    ) {
        if (trim($path) === '') {
            throw new InvalidArgumentException('File path must not be empty.');
        }

        if (trim($message) === '') {
            throw new InvalidArgumentException('Commit message must not be empty.');
        }

        if (trim($branch) === '') {
            throw new InvalidArgumentException('Branch must not be empty.');
        }
    }
}
