<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Dto\PullRequestReview;
use RoundlyConsulting\Git\Dto\PullRequestReviewComment;
use RoundlyConsulting\Git\Dto\PullRequestReviews;
use RoundlyConsulting\Git\Enums\ProviderName;

/**
 * Closures rather than `function` declarations: a named helper only exists in the
 * parallel worker that happens to own the file declaring it, which is the failure
 * `tests/Pest.php` documents. These are used nowhere else, so they stay local.
 */
$review = fn (string $id, string $state, ?string $submittedAt): PullRequestReview => new PullRequestReview(
    provider: ProviderName::Github,
    id: $id,
    state: $state,
    body: null,
    author: null,
    url: null,
    submittedAt: $submittedAt === null ? null : Carbon::parse($submittedAt),
);

$finding = fn (string $id, ?string $reviewId): PullRequestReviewComment => new PullRequestReviewComment(
    provider: ProviderName::Github,
    id: $id,
    body: "finding {$id}",
    path: 'app/Foo.php',
    line: 1,
    side: null,
    author: null,
    url: null,
    createdAt: null,
    reviewId: $reviewId,
);

it('skips a pending draft when it names the current verdict', function () use ($review) {
    // The trap this method closes: a forge serves reviews ascending, so `end()` is
    // usually the newest — but an UNSUBMITTED draft still sorts last, and reading it as
    // the verdict reports a decision nobody has published.
    $reviews = new PullRequestReviews(
        reviews: [
            $review('11', 'COMMENTED', '2020-01-01T00:00:00Z'),
            $review('12', 'CHANGES_REQUESTED', '2020-01-03T00:00:00Z'),
            $review('13', 'PENDING', null),
        ],
        comments: [],
    );

    expect($reviews->latest()?->id)->toBe('12');
});

it('compares reviews by time rather than by position', function () use ($review) {
    // Ascending is the forge's habit, not a guarantee — and this must not be the place
    // that quietly depends on it.
    $reviews = new PullRequestReviews(
        reviews: [
            $review('11', 'APPROVED', '2020-01-05T00:00:00Z'),
            $review('12', 'COMMENTED', '2020-01-02T00:00:00Z'),
        ],
        comments: [],
    );

    expect($reviews->latest()?->id)->toBe('11');
});

it('names no verdict when every review is still a draft', function () use ($review) {
    $reviews = new PullRequestReviews([$review('13', 'PENDING', null)], []);

    expect($reviews->latest())->toBeNull()
        ->and($reviews->isEmpty())->toBeFalse();
});

it('reads an unreviewed pull request as empty', function () {
    expect((new PullRequestReviews([], []))->isEmpty())->toBeTrue()
        ->and((new PullRequestReviews([], []))->latest())->toBeNull();
});

it('answers no findings for a review that anchored none', function () use ($review, $finding) {
    $reviews = new PullRequestReviews(
        reviews: [$review('11', 'APPROVED', '2020-01-01T00:00:00Z')],
        comments: [$finding('21', '12'), $finding('22', null)],
    );

    // Neither the OTHER review's findings nor the unattached reply leak in — a caller
    // rendering "approved, with these findings" would otherwise show somebody else's.
    expect($reviews->commentsFor('11'))->toBe([]);
});

it('keeps a filtered finding list indexed as a list', function () use ($finding) {
    // `array_filter` preserves keys, and a gapped array serializes to a JSON OBJECT
    // rather than an array — which every consumer decoding this would then mis-shape.
    $reviews = new PullRequestReviews(
        reviews: [],
        comments: [$finding('21', '11'), $finding('22', '12'), $finding('23', '11')],
    );

    expect(array_keys($reviews->commentsFor('11')))->toBe([0, 1]);
});
