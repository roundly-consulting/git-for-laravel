<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use InvalidArgumentException;

final readonly class NewRelease
{
    public function __construct(
        public string $tagName,
        public ?string $name = null,
        public ?string $body = null,
        public bool $draft = false,
        public bool $prerelease = false,
    ) {
        if (trim($tagName) === '') {
            throw new InvalidArgumentException('Release tag name must not be empty.');
        }
    }
}
