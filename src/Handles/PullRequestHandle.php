<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Handles;

use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Dto\Input\NewReview;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\PullRequestReview;
use RoundlyConsulting\Git\Dto\PullRequestReviews;
use RoundlyConsulting\Git\Enums\MergeMethod;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Interfaces\Provider;

/**
 * One pull request of one repository — `Git::github()->repo('acme/app')->pullRequest(12)`.
 *
 * Built only by {@see RepositoryHandle::pullRequest()}, so it is always scoped to the
 * repository it came from; each method is the matching {@see Provider} write or read.
 */
final readonly class PullRequestHandle
{
    /**
     * @throws OutOfScopeException when the number is below 1
     */
    public function __construct(
        private Provider $provider,
        private string $path,
        private int $number,
    ) {
        PathGuard::repository($path);

        if ($number < 1) {
            throw OutOfScopeException::pullRequestNumber($number);
        }
    }

    public function number(): int
    {
        return $this->number;
    }

    public function get(): PullRequest
    {
        return $this->provider->pullRequest($this->path, $this->number);
    }

    /**
     * Merge it; returns the merge commit sha. Pass the head `$sha` you reviewed so a push
     * that landed since cannot be merged unseen. A refused merge throws.
     */
    public function merge(
        MergeMethod $method = MergeMethod::Merge,
        ?string $sha = null,
        ?string $title = null,
        ?string $message = null,
    ): string {
        return $this->provider->mergePullRequest($this->path, $this->number, $method, $sha, $title, $message);
    }

    /** Approve it as the authenticated account; returns the review's state. */
    public function approve(?string $body = null): string
    {
        return $this->provider->approvePullRequest($this->path, $this->number, $body);
    }

    /** Publish a whole review — verdict, summary and inline comments — in one request. */
    public function review(NewReview $review): PullRequestReview
    {
        return $this->provider->reviewPullRequest($this->path, $this->number, $review);
    }

    /** Every review left on it, with the inline comments they anchored. */
    public function reviews(int $perPage = 100, int $maxPages = 5): PullRequestReviews
    {
        return $this->provider->pullRequestReviews($this->path, $this->number, $perPage, $maxPages);
    }

    /** Close it WITHOUT merging. */
    public function close(): PullRequest
    {
        return $this->provider->closePullRequest($this->path, $this->number);
    }

    public function comment(string $body): Comment
    {
        return $this->provider->comment($this->path, new NewComment($this->number, $body));
    }
}
