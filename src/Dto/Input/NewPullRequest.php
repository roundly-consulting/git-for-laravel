<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use InvalidArgumentException;

final readonly class NewPullRequest
{
    public function __construct(
        public string $title,
        public string $head,
        public string $base,
        public ?string $body = null,
    ) {
        if (trim($title) === '') {
            throw new InvalidArgumentException('Pull request title must not be empty.');
        }

        if (trim($head) === '') {
            throw new InvalidArgumentException('Pull request head ref must not be empty.');
        }

        if (trim($base) === '') {
            throw new InvalidArgumentException('Pull request base ref must not be empty.');
        }
    }
}
