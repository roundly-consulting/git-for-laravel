<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use InvalidArgumentException;
use RoundlyConsulting\Git\Enums\CommentTarget;

final readonly class NewComment
{
    /**
     * @param  CommentTarget|null  $target  whether `$number` is an issue or a pull request —
     *                                      required on GitLab, which numbers them separately
     */
    public function __construct(
        public int $number,
        public string $body,
        public ?CommentTarget $target = null,
    ) {
        if (trim($body) === '') {
            throw new InvalidArgumentException('Comment body must not be empty.');
        }
    }
}
