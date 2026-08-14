<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

/**
 * Everything said in review on one pull request: the reviews, and the inline comments
 * they anchored.
 *
 * Two lists in one DTO rather than two calls, because they are two halves of one answer
 * and a forge serves them from two endpoints — a caller that fetched only the reviews
 * would read "changes requested" with none of the findings that explain it.
 */
final readonly class PullRequestReviews extends Dto
{
    /**
     * @param  list<PullRequestReview>  $reviews
     * @param  list<PullRequestReviewComment>  $comments
     */
    public function __construct(
        public array $reviews,
        public array $comments,
    ) {}

    /**
     * The most recently SUBMITTED review — the current verdict, or null if there is none.
     *
     * Not `end($reviews)`, which is the trap this method exists to close: a forge serves
     * reviews ascending, so the last element is usually the newest — but a PENDING draft
     * has no `submittedAt` and still sorts last, so the naive read hands back a review
     * nobody has published as "the verdict". Drafts are skipped here, and the rest are
     * compared by time rather than by position.
     */
    public function latest(): ?PullRequestReview
    {
        $latest = null;

        foreach ($this->reviews as $review) {
            if ($review->submittedAt === null) {
                continue;
            }

            if ($latest === null || $review->submittedAt->greaterThan($latest->submittedAt)) {
                $latest = $review;
            }
        }

        return $latest;
    }

    /**
     * The inline comments published under one review.
     *
     * The join the two endpoints do not do for you: `/reviews` carries the verdicts and
     * `/comments` the findings, and only the comment side carries the id that links them.
     *
     * @return list<PullRequestReviewComment>
     */
    public function commentsFor(PullRequestReview|string $review): array
    {
        $id = $review instanceof PullRequestReview ? $review->id : $review;

        return array_values(array_filter(
            $this->comments,
            fn (PullRequestReviewComment $comment): bool => $comment->reviewId === $id,
        ));
    }

    /** Whether the pull request has been reviewed at all — no reviews and no comments. */
    public function isEmpty(): bool
    {
        return $this->reviews === [] && $this->comments === [];
    }
}
