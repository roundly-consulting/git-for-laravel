<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use InvalidArgumentException;

final readonly class NewComment
{
    public function __construct(
        public int $number,
        public string $body,
    ) {
        if (trim($body) === '') {
            throw new InvalidArgumentException('Comment body must not be empty.');
        }
    }
}
