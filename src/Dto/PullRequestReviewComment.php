<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Carbon\CarbonInterface;
use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\DiffSide;
use RoundlyConsulting\Git\Enums\ProviderName;

/**
 * One inline comment on a pull request's diff — a review's finding, where it is about.
 *
 * `line` is nullable because a comment whose line has since been rewritten is OUTDATED:
 * the forge keeps the comment and drops its anchor. Reading that as line 0, or as the
 * file's first line, would point a reader at code the comment never mentioned. `side`
 * is nullable for the narrower reason that a payload may not carry one at all.
 *
 * `reviewId` is the review this comment was published under — the join back to
 * {@see PullRequestReview}, and what {@see PullRequestReviews::commentsFor()} matches
 * on. Null for a comment that belongs to no review, which is what a standalone reply on
 * the diff is.
 */
final readonly class PullRequestReviewComment extends Dto
{
    use HasRawPayload;

    /** @param array<string, mixed> $raw */
    public function __construct(
        public ProviderName $provider,
        public string $id,
        public string $body,
        public string $path,
        public ?int $line,
        public ?DiffSide $side,
        public ?Author $author,
        public ?string $url,
        public ?CarbonInterface $createdAt,
        public ?string $reviewId = null,
        public array $raw = [],
    ) {}

    /**
     * Whether the diff moved out from under this comment.
     *
     * The forge drops the anchor and keeps the comment, so there is no line to show the
     * reader and no line to act on — which is the one thing a caller rendering findings
     * has to branch on.
     */
    public function isOutdated(): bool
    {
        return $this->line === null;
    }
}
