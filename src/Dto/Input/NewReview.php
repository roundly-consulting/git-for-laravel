<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use InvalidArgumentException;
use RoundlyConsulting\Git\Enums\ReviewEvent;

/**
 * A whole review, submitted in one call: the verdict, the summary, and every inline
 * comment it anchors.
 *
 * One request rather than a comment at a time, because a forge publishes a review
 * atomically — posting the comments separately leaves half a review visible on the pull
 * request the moment anything fails partway.
 */
final readonly class NewReview
{
    /** @param list<NewReviewComment> $comments */
    public function __construct(
        public ReviewEvent $event,
        public ?string $body = null,
        public array $comments = [],
    ) {
        // A forge refuses a `COMMENT` or `REQUEST_CHANGES` review with no body (`422`),
        // and answers it exactly as it answers a comment anchored off the diff — so the
        // one that is knowable here is caught here, where the message can say which.
        if ($event !== ReviewEvent::Approve && trim((string) $body) === '') {
            throw new InvalidArgumentException('A review that is not an approval must have a body.');
        }
    }
}
