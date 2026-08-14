<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use InvalidArgumentException;
use RoundlyConsulting\Git\Enums\DiffSide;

/**
 * One inline comment in a review: a body, anchored to a line — or a span of lines — of
 * a file.
 *
 * **The anchor must be one the pull request's diff actually contains.** A forge answers
 * `422` for a comment on a line nobody changed — a decline the caller has to be told
 * about (the review was written against the wrong anchor), never a transport failure.
 * Nothing here can pre-empt that: only the forge knows the diff.
 */
final readonly class NewReviewComment
{
    public function __construct(
        /** Repository-relative path, exactly as the diff spells it. */
        public string $path,
        /** The line the comment is anchored to, 1-based. The LAST line of a span. */
        public int $line,
        public string $body,
        public DiffSide $side = DiffSide::Right,
        /**
         * The first line of a multi-line anchor, when the finding is about a span
         * rather than a line. Null anchors the comment to `$line` alone.
         */
        public ?int $startLine = null,
    ) {
        if (trim($path) === '') {
            throw new InvalidArgumentException('Review comment path must not be empty.');
        }

        if (trim($body) === '') {
            throw new InvalidArgumentException('Review comment body must not be empty.');
        }

        if ($line < 1) {
            throw new InvalidArgumentException('Review comment line must be 1 or greater.');
        }

        if ($startLine !== null && $startLine < 1) {
            throw new InvalidArgumentException('Review comment start line must be 1 or greater.');
        }

        // A forge reads the span as `start_line`..`line` and rejects it inverted or
        // collapsed (`422`). Caught here because it is knowable without the diff, and
        // because the fix — swapping the two — is not one a caller can guess from the
        // forge's message.
        if ($startLine !== null && $startLine >= $line) {
            throw new InvalidArgumentException('Review comment start line must come before its line.');
        }
    }
}
