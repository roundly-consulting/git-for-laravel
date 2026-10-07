<?php

declare(strict_types=1);

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Comparison;
use RoundlyConsulting\Git\Dto\Contributor;
use RoundlyConsulting\Git\Dto\FileContent;
use RoundlyConsulting\Git\Dto\Input\NewBranch;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Dto\Input\NewFile;
use RoundlyConsulting\Git\Dto\Input\NewPullRequest;
use RoundlyConsulting\Git\Dto\Input\NewRelease;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Input\NewReview;
use RoundlyConsulting\Git\Dto\Input\NewReviewComment;
use RoundlyConsulting\Git\Dto\Input\NewTag;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Input\UpdatedFile;
use RoundlyConsulting\Git\Dto\Issue;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\DiffSide;
use RoundlyConsulting\Git\Enums\MergeMethod;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ReviewEvent;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Facades\Git;

it('lists pull requests', function () {
    Http::fake(['*/repos/o/r/pulls*' => Http::response([[
        'id' => 1, 'number' => 7, 'title' => 'Add CI', 'body' => 'b', 'state' => 'open',
        'head' => ['ref' => 'feature'], 'base' => ['ref' => 'main'],
        'user' => ['login' => 'octocat', 'avatar_url' => 'a'],
        'html_url' => 'url', 'created_at' => '2020-01-01T00:00:00Z',
    ]])]);

    $page = github()->pullRequests('o/r');

    expect($page->first())->toBeInstanceOf(PullRequest::class)
        ->number->toBe(7)->sourceBranch->toBe('feature')->targetBranch->toBe('main');
});

it('gets a single pull request', function () {
    Http::fake(['*/repos/o/r/pulls/7' => Http::response([
        'id' => 1, 'number' => 7, 'title' => 't', 'state' => 'open',
        'head' => ['ref' => 'f'], 'base' => ['ref' => 'm'], 'created_at' => '2020-01-01T00:00:00Z',
    ])]);

    expect(github()->pullRequest('o/r', 7))->number->toBe(7);
});

it('lists and gets issues', function () {
    Http::fake([
        '*/repos/o/r/issues/3' => Http::response([
            'id' => 9, 'number' => 3, 'title' => 'bug', 'state' => 'open', 'created_at' => '2020-01-01T00:00:00Z',
        ]),
        '*/repos/o/r/issues*' => Http::response([[
            'id' => 9, 'number' => 3, 'title' => 'bug', 'state' => 'open',
            'user' => ['login' => 'octocat'], 'created_at' => '2020-01-01T00:00:00Z',
        ]]),
    ]);

    expect(github()->issues('o/r')->first())->toBeInstanceOf(Issue::class)->number->toBe(3)
        ->and(github()->issue('o/r', 3))->title->toBe('bug');
});

it('leaves pull requests out of the issue list, keeping the next-page signal', function () {
    // GitHub's issues endpoint returns pull requests too, each carrying a `pull_request` key.
    $issue = fn (int $number): array => [
        'id' => 1000 + $number, 'number' => $number, 'title' => "Bug {$number}", 'state' => 'open',
        'body' => 'b', 'user' => ['login' => 'octocat', 'avatar_url' => 'a'],
        'html_url' => "https://github.com/o/r/issues/{$number}", 'created_at' => '2020-01-01T00:00:00Z',
    ];
    $pull = fn (int $number): array => $issue($number) + ['pull_request' => [
        'url' => "https://api.github.com/repos/o/r/pulls/{$number}",
        'html_url' => "https://github.com/o/r/pull/{$number}",
        'diff_url' => "https://github.com/o/r/pull/{$number}.diff",
        'patch_url' => "https://github.com/o/r/pull/{$number}.patch",
        'merged_at' => null,
    ]];

    Http::fake(['*/repos/o/r/issues*' => Http::response(
        [$pull(5), $issue(4), $pull(3), $issue(2), $pull(1)],
        200,
        ['Link' => '<https://api.github.com/repositories/1/issues?state=open&per_page=5&page=2>; rel="next"'],
    )]);

    $page = github()->issues('o/r', perPage: 5);

    expect($page->collect()->pluck('number')->all())->toBe([4, 2])
        ->and($page->hasMore)->toBeTrue();
});

it('lists tags and releases', function () {
    Http::fake([
        '*/repos/o/r/tags*' => Http::response([['name' => 'v1.0', 'commit' => ['sha' => 'abc', 'url' => 'u']]]),
        '*/repos/o/r/releases/tags/v1.0' => Http::response([
            'id' => 5, 'tag_name' => 'v1.0', 'name' => 'One', 'body' => 'notes', 'draft' => false,
            'prerelease' => false, 'html_url' => 'u', 'created_at' => '2020-01-01T00:00:00Z',
        ]),
        '*/repos/o/r/releases*' => Http::response([[
            'id' => 5, 'tag_name' => 'v1.0', 'name' => 'One', 'draft' => false, 'prerelease' => false,
        ]]),
    ]);

    expect(github()->tags('o/r')->first())->toBeInstanceOf(Tag::class)->name->toBe('v1.0')
        ->and(github()->releases('o/r')->first())->toBeInstanceOf(Release::class)->tagName->toBe('v1.0')
        ->and(github()->release('o/r', 'v1.0'))->name->toBe('One');
});

it('fetches decoded file contents', function () {
    Http::fake(['*/repos/o/r/contents/README.md*' => Http::response([
        'path' => 'README.md', 'content' => base64_encode('hello'), 'sha' => 'abc', 'size' => 5, 'html_url' => 'u',
    ])]);

    expect(github()->contents('o/r', 'README.md', 'main'))
        ->toBeInstanceOf(FileContent::class)->content->toBe('hello')->sha->toBe('abc');
});

it('compares two refs', function () {
    Http::fake(['*/repos/o/r/compare/a...b' => Http::response([
        'ahead_by' => 2, 'behind_by' => 1,
        'files' => [['filename' => 'x.php', 'status' => 'modified', 'additions' => 3, 'deletions' => 1]],
    ])]);

    $comparison = github()->compare('o/r', 'a', 'b');

    expect($comparison)->toBeInstanceOf(Comparison::class)->aheadBy->toBe(2)
        ->and($comparison->files[0]->filename)->toBe('x.php');
});

it('lists contributors and languages', function () {
    Http::fake([
        '*/repos/o/r/contributors*' => Http::response([['id' => 1, 'login' => 'octocat', 'avatar_url' => 'a', 'contributions' => 42]]),
        '*/repos/o/r/languages' => Http::response(['PHP' => 100, 'Blade' => 20]),
    ]);

    expect(github()->contributors('o/r')->first())->toBeInstanceOf(Contributor::class)->contributions->toBe(42)
        ->and(github()->languages('o/r'))->toBe(['PHP' => 100, 'Blade' => 20]);
});

it('searches repositories', function () {
    Http::fake(['*/search/repositories*' => Http::response([
        'items' => [[
            'id' => 1, 'full_name' => 'o/r', 'name' => 'r', 'description' => null, 'default_branch' => 'main',
            'owner' => ['id' => 1, 'login' => 'o'], 'created_at' => '2020-01-01T00:00:00Z', 'pushed_at' => '2020-01-02T00:00:00Z',
        ]],
    ])]);

    expect(github()->searchRepositories('laravel')->first())->toBeInstanceOf(Repository::class)->name->toBe('r');
});

it('creates a repository', function () {
    Http::fake(['*/user/repos' => Http::response([
        'id' => 1, 'full_name' => 'o/acme', 'name' => 'acme', 'description' => 'd', 'default_branch' => 'main',
        'owner' => ['id' => 1, 'login' => 'o'], 'created_at' => '2020-01-01T00:00:00Z', 'pushed_at' => null,
    ])]);

    $repo = github()->createRepository(new NewRepository(name: 'acme', private: true, description: 'd'));

    expect($repo)->toBeInstanceOf(Repository::class)->name->toBe('acme');
    Http::assertSent(fn ($r): bool => $r->method() === 'POST' && $r['name'] === 'acme' && $r['private'] === true);
});

it('creates a branch from a base ref', function () {
    Http::fake([
        '*/repos/o/r/git/refs/heads/main' => Http::response(['object' => ['sha' => 'basesha']]),
        '*/repos/o/r/git/refs' => Http::response(['ref' => 'refs/heads/feature']),
    ]);

    expect(github()->createBranch('o/r', new NewBranch('feature', 'main')))->toBe('refs/heads/feature');
});

it('creates and updates a file', function () {
    Http::fake(['*/repos/o/r/contents/*' => Http::response([
        'commit' => ['sha' => 'c1', 'message' => 'add', 'author' => ['name' => 'n', 'email' => 'e', 'date' => '2020-01-01T00:00:00Z'], 'html_url' => 'u'],
    ])]);

    expect(github()->createFile('o/r', new NewFile('a.txt', 'x', 'add', 'main'))->sha)->toBe('c1')
        ->and(github()->updateFile('o/r', new UpdatedFile('a.txt', 'y', 'edit', 'main', 'old'))->sha)->toBe('c1');
});

it('creates a pull request', function () {
    Http::fake(['*/repos/o/r/pulls' => Http::response([
        'id' => 1, 'number' => 7, 'title' => 'Add CI', 'state' => 'open',
        'head' => ['ref' => 'feature'], 'base' => ['ref' => 'main'], 'created_at' => '2020-01-01T00:00:00Z',
    ])]);

    expect(github()->createPullRequest('o/r', new NewPullRequest('Add CI', 'feature', 'main', 'body')))
        ->number->toBe(7);
});

it('closes a pull request with the state update GitHub actually takes', function () {
    // There is no "close" endpoint: closing IS a state PATCH, and the same call reopens.
    Http::fake(['*/repos/o/r/pulls/7' => Http::response([
        'id' => 1, 'number' => 7, 'title' => 'Add CI', 'state' => 'closed',
        'head' => ['ref' => 'feature'], 'base' => ['ref' => 'main'], 'created_at' => '2020-01-01T00:00:00Z',
    ])]);

    expect(github()->closePullRequest('o/r', 7))->number->toBe(7);

    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH' && $request['state'] === 'closed');
});

it('approves a pull request as a review, because GitHub has no approve endpoint', function () {
    Http::fake(['*/repos/o/r/pulls/7/reviews' => Http::response(['id' => 5, 'state' => 'APPROVED'])]);

    // The review's own state, so a caller reports what happened instead of asserting an
    // outcome it never observed.
    expect(github()->approvePullRequest('o/r', 7, 'Looks good.'))->toBe('APPROVED');

    Http::assertSent(fn ($request): bool => $request['event'] === 'APPROVE' && $request['body'] === 'Looks good.');
});

it('omits an absent review body rather than sending null', function () {
    Http::fake(['*/repos/o/r/pulls/7/reviews' => Http::response(['id' => 5])]);

    github()->approvePullRequest('o/r', 7);

    Http::assertSent(fn ($request): bool => ! array_key_exists('body', $request->data()));
});

it('publishes a review with its inline comments in ONE request', function () {
    // Atomic on purpose: GitHub publishes a review as a unit, so posting the comments
    // separately would leave half a review visible the moment anything failed partway.
    Http::fake(['*/repos/o/r/pulls/7/reviews' => Http::response([
        'id' => 11, 'state' => 'COMMENTED', 'body' => '**Verdict: changes_requested**',
        'user' => ['login' => 'cosmos[bot]', 'avatar_url' => 'a'],
        'html_url' => 'url', 'submitted_at' => '2020-01-01T00:00:00Z',
    ])]);

    $review = github()->reviewPullRequest('o/r', 7, new NewReview(
        event: ReviewEvent::Comment,
        body: '**Verdict: changes_requested**',
        comments: [new NewReviewComment('app/Foo.php', 42, 'This nulls out on the retry.')],
    ));

    expect($review->state)->toBe('COMMENTED')
        ->and($review->author?->name)->toBe('cosmos[bot]')
        ->and($review->submittedAt?->toDateString())->toBe('2020-01-01');

    Http::assertSent(fn ($request): bool => $request['event'] === 'COMMENT'
        && $request['comments'][0]['path'] === 'app/Foo.php'
        && $request['comments'][0]['line'] === 42
        // Defaulted, and sent in GitHub's own spelling — the head is what a review of a
        // change is about, and LEFT would anchor the finding to the file as it is today.
        && $request['comments'][0]['side'] === 'RIGHT');
});

it('anchors a finding to a SPAN of lines when it is given one', function () {
    Http::fake(['*/repos/o/r/pulls/7/reviews' => Http::response(['id' => 11, 'state' => 'COMMENTED'])]);

    github()->reviewPullRequest('o/r', 7, new NewReview(
        event: ReviewEvent::Comment,
        body: 'One finding about a block.',
        comments: [new NewReviewComment('app/Foo.php', 48, 'This whole branch is unreachable.', DiffSide::Right, startLine: 42)],
    ));

    Http::assertSent(function ($request): bool {
        $comment = $request['comments'][0];

        // `start_side` travels with `start_line`: a span still has to name a side, and
        // GitHub only defaults it when the key is absent.
        return $comment['start_line'] === 42 && $comment['line'] === 48
            && $comment['start_side'] === 'RIGHT' && $comment['side'] === 'RIGHT';
    });
});

it('omits the span keys entirely on a single-line comment', function () {
    // Not "sends them as null": GitHub reads the PRESENCE of `start_line` as "this is a
    // multi-line comment" and 422s a span that starts where it ends.
    Http::fake(['*/repos/o/r/pulls/7/reviews' => Http::response(['id' => 11, 'state' => 'COMMENTED'])]);

    github()->reviewPullRequest('o/r', 7, new NewReview(
        event: ReviewEvent::Comment,
        body: 'One finding.',
        comments: [new NewReviewComment('app/Foo.php', 42, 'This nulls out.')],
    ));

    Http::assertSent(fn ($request): bool => ! array_key_exists('start_line', $request['comments'][0])
        && ! array_key_exists('start_side', $request['comments'][0]));
});

it('omits an empty comment list rather than sending one', function () {
    Http::fake(['*/repos/o/r/pulls/7/reviews' => Http::response(['id' => 11, 'state' => 'COMMENTED'])]);

    github()->reviewPullRequest('o/r', 7, new NewReview(ReviewEvent::Comment, 'Nothing to flag.'));

    Http::assertSent(fn ($request): bool => ! array_key_exists('comments', $request->data()));
});

it('leaves a pending review unsubmitted rather than dating it to the epoch', function () {
    // A pending review has no `submitted_at`. Null says "not submitted"; a parsed empty
    // string would say "submitted in 1970", which a reader sorts to the top.
    Http::fake(['*/repos/o/r/pulls/7/reviews' => Http::response(['id' => 11, 'state' => 'PENDING', 'body' => ''])]);

    $review = github()->reviewPullRequest('o/r', 7, new NewReview(ReviewEvent::Comment, 'x'));

    expect($review->submittedAt)->toBeNull()
        ->and($review->body)->toBeNull();
});

it('THROWS when a comment is anchored to a line outside the diff', function () {
    // GitHub's own 422. A decline the caller has to be TOLD about — the review was
    // written against a line nobody changed — never dressed up as the provider failing.
    Http::fake(['*/repos/o/r/pulls/7/reviews' => Http::response([
        'message' => 'Validation Failed',
        'errors' => [['resource' => 'PullRequestReviewComment', 'field' => 'line']],
    ], 422)]);

    expect(fn () => github()->reviewPullRequest('o/r', 7, new NewReview(
        event: ReviewEvent::Comment,
        body: 'Findings.',
        comments: [new NewReviewComment('app/Foo.php', 9_999, 'Out of the diff.')],
    )))->toThrow(RequestException::class);
});

it('reads the reviews and their inline comments from the two endpoints they live on', function () {
    Http::fake([
        '*/repos/o/r/pulls/7/reviews*' => Http::response([[
            'id' => 11, 'state' => 'COMMENTED', 'body' => 'Two findings.',
            'user' => ['login' => 'reviewer'], 'submitted_at' => '2020-01-02T00:00:00Z',
        ]]),
        '*/repos/o/r/pulls/7/comments*' => Http::response([
            [
                'id' => 21, 'body' => 'This nulls out.', 'path' => 'app/Foo.php', 'line' => 42,
                'side' => 'RIGHT', 'user' => ['login' => 'reviewer'], 'created_at' => '2020-01-02T00:00:00Z',
            ],
            // Outdated: the lines it was anchored to were rewritten, so GitHub drops the
            // anchor and keeps the comment.
            ['id' => 22, 'body' => 'Stale.', 'path' => 'app/Bar.php', 'line' => null, 'side' => 'RIGHT'],
        ]),
    ]);

    $reviews = github()->pullRequestReviews('o/r', 7);

    expect($reviews->reviews)->toHaveCount(1)
        ->and($reviews->reviews[0]->state)->toBe('COMMENTED')
        ->and($reviews->comments)->toHaveCount(2)
        ->and($reviews->comments[0]->line)->toBe(42)
        ->and($reviews->comments[0]->side)->toBe(DiffSide::Right)
        ->and($reviews->comments[0]->isOutdated())->toBeFalse()
        ->and($reviews->comments[1]->line)->toBeNull()
        ->and($reviews->comments[1]->isOutdated())->toBeTrue()
        ->and($reviews->comments[1]->createdAt)->toBeNull();
});

it('dates a comment to null rather than to NOW when the payload carries no timestamp', function () {
    // `Carbon::parse('')` is `now()`, so an empty `created_at` would date the finding to
    // the moment it was READ — which sorts to the top of the thread and reads as the
    // newest thing anyone said about the pull request.
    Http::fake([
        '*/pulls/7/reviews*' => Http::response([['id' => 1, 'state' => 'COMMENTED', 'submitted_at' => '']]),
        '*/pulls/7/comments*' => Http::response([
            ['id' => 21, 'body' => 'x', 'path' => 'a.php', 'line' => 1, 'side' => 'RIGHT', 'created_at' => ''],
        ]),
    ]);

    $reviews = github()->pullRequestReviews('o/r', 7);

    expect($reviews->comments[0]->createdAt)->toBeNull()
        ->and($reviews->reviews[0]->submittedAt)->toBeNull();
});

it('joins each finding back to the review that published it', function () {
    // The two endpoints do not do this join: `/reviews` carries the verdict, `/comments`
    // the findings, and only the comment side carries the id that links them. Without it
    // a caller reading "changes requested" cannot say WHICH findings explain it.
    Http::fake([
        '*/pulls/7/reviews*' => Http::response([
            ['id' => 11, 'state' => 'COMMENTED', 'submitted_at' => '2020-01-01T00:00:00Z'],
            ['id' => 12, 'state' => 'CHANGES_REQUESTED', 'submitted_at' => '2020-01-03T00:00:00Z'],
        ]),
        '*/pulls/7/comments*' => Http::response([
            ['id' => 21, 'body' => 'Old.', 'path' => 'a.php', 'line' => 1, 'pull_request_review_id' => 11],
            ['id' => 22, 'body' => 'Blocking.', 'path' => 'b.php', 'line' => 2, 'pull_request_review_id' => 12],
            // A standalone reply on the diff belongs to no review at all.
            ['id' => 23, 'body' => 'Reply.', 'path' => 'b.php', 'line' => 2],
        ]),
    ]);

    $reviews = github()->pullRequestReviews('o/r', 7);
    $latest = $reviews->latest();

    expect($latest?->state)->toBe('CHANGES_REQUESTED')
        ->and($reviews->comments[0]->reviewId)->toBe('11')
        ->and($reviews->comments[2]->reviewId)->toBeNull()
        ->and($reviews->commentsFor($latest))->toHaveCount(1)
        ->and($reviews->commentsFor($latest)[0]->body)->toBe('Blocking.')
        ->and($reviews->commentsFor('11'))->toHaveCount(1)
        ->and($reviews->isEmpty())->toBeFalse();
});

it('walks the review pages, because GitHub serves the OLDEST first', function () {
    // A single page of a long-lived pull request is its oldest reviews, and there is no
    // `direction` on this endpoint — so a caller looking for "the latest verdict" would
    // read the hundredth-oldest one and act on it.
    $page = 0;
    Http::fake(function ($request) use (&$page) {
        if (str_contains((string) $request->url(), '/comments')) {
            return Http::response([]);
        }

        $page++;

        // Page 1 is FULL (per_page=2), so there must be a second request; page 2 is short
        // and ends the walk.
        return Http::response($page === 1
            ? [['id' => 1, 'state' => 'COMMENTED'], ['id' => 2, 'state' => 'COMMENTED']]
            : [['id' => 3, 'state' => 'APPROVED']]);
    });

    $reviews = github()->pullRequestReviews('o/r', 7, perPage: 2);

    expect($reviews->reviews)->toHaveCount(3)
        ->and($reviews->reviews[2]->state)->toBe('APPROVED');
});

it('stops walking at the page cap rather than paging a pathological thread forever', function () {
    Http::fake(['*' => Http::response([['id' => 1, 'state' => 'COMMENTED']])]);

    // Every page comes back FULL, so only the cap ends it.
    $reviews = github()->pullRequestReviews('o/r', 7, perPage: 1, maxPages: 3);

    expect($reviews->reviews)->toHaveCount(3);
});

it('names nobody rather than an empty author for a deleted account', function () {
    // GitHub sends a review with no `user` when the account is gone. An `Author` whose
    // name is `''` reads as "somebody" to every null check downstream.
    Http::fake([
        '*/pulls/7/reviews*' => Http::response([['id' => 1, 'state' => 'COMMENTED', 'user' => ['login' => '']]]),
        '*/pulls/7/comments*' => Http::response([]),
    ]);

    expect(github()->pullRequestReviews('o/r', 7)->reviews[0]->author)->toBeNull();
});

it('merges a pull request and returns the merge commit', function () {
    Http::fake(['*/repos/o/r/pulls/7/merge' => Http::response(['merged' => true, 'sha' => 'abc'])]);

    expect(github()->mergePullRequest('o/r', 7, MergeMethod::Squash))->toBe('abc');

    Http::assertSent(
        fn ($request): bool => $request->method() === 'PUT' && $request['merge_method'] === 'squash',
    );
});

it('defaults to a merge commit and cannot be handed a method the forge would 405', function () {
    // The merge method is a CLOSED set the repository can disallow, so a typo and a
    // genuinely refused method are the same 405. The enum moves the typo to the call site.
    Http::fake(['*/repos/o/r/pulls/7/merge' => Http::response(['merged' => true, 'sha' => 'abc'])]);

    github()->mergePullRequest('o/r', 7);

    Http::assertSent(fn ($request): bool => $request['merge_method'] === 'merge');

    expect(MergeMethod::cases())->toHaveCount(3)
        ->and(array_map(fn (MergeMethod $m): string => $m->value, MergeMethod::cases()))
        ->toBe(['merge', 'squash', 'rebase']);
});

it('makes the merge conditional on the head the caller decided about', function () {
    // Without `sha`, a push landing between the review and the merge is merged unseen.
    Http::fake(['*/repos/o/r/pulls/7/merge' => Http::response(['merged' => true, 'sha' => 'abc'])]);

    github()->mergePullRequest('o/r', 7, MergeMethod::Merge, 'head-sha');

    Http::assertSent(fn ($request): bool => $request['sha'] === 'head-sha');
});

it('THROWS when GitHub will not merge, because a 200 always means it merged', function () {
    // `merged: false` is a shape GitHub does not send: an unmergeable pull request is
    // `405` and a conflict (or a moved head) is `409`. A caller that read a boolean would
    // be reading a response that never arrives.
    Http::fake(['*/repos/o/r/pulls/7/merge' => Http::response(['message' => 'Pull Request is not mergeable'], 405)]);

    expect(fn () => github()->mergePullRequest('o/r', 7))
        ->toThrow(RequestException::class);
});

it('closes only — it cannot be talked into reopening', function () {
    Http::fake(['*/repos/o/r/pulls/7' => Http::response([
        'id' => 1, 'number' => 7, 'title' => 'Add CI', 'state' => 'closed',
        'head' => ['ref' => 'feature'], 'base' => ['ref' => 'main'], 'created_at' => '2020-01-01T00:00:00Z',
    ])]);

    github()->closePullRequest('o/r', 7);

    Http::assertSent(fn ($request): bool => $request['state'] === 'closed');
});

it('comments on an issue', function () {
    Http::fake(['*/repos/o/r/issues/7/comments' => Http::response([
        'id' => 11, 'body' => 'nice', 'user' => ['login' => 'octocat'], 'html_url' => 'u', 'created_at' => '2020-01-01T00:00:00Z',
    ])]);

    expect(github()->comment('o/r', new NewComment(7, 'nice')))->toBeInstanceOf(Comment::class)->body->toBe('nice');
});

it('creates a release and a tag', function () {
    Http::fake([
        '*/repos/o/r/releases' => Http::response(['id' => 5, 'tag_name' => 'v2', 'name' => 'Two', 'draft' => false, 'prerelease' => false]),
        '*/repos/o/r/git/refs' => Http::response(['object' => ['sha' => str_repeat('a', 40)], 'url' => 'u']),
    ]);

    expect(github()->createRelease('o/r', new NewRelease('v2', 'Two'))->tagName)->toBe('v2')
        ->and(github()->createTag('o/r', new NewTag('v2', str_repeat('a', 40)))->sha)->toBe(str_repeat('a', 40));
});

it('resolves a branch to its head commit before tagging it', function () {
    $sha = str_repeat('b', 40);

    Http::fake([
        '*/repos/o/r/commits/main' => Http::response(['sha' => $sha]),
        '*/repos/o/r/git/refs' => Http::response(['ref' => 'refs/tags/v1.0.1', 'object' => ['sha' => $sha], 'url' => 'u']),
    ]);

    $tag = github()->repo('o/r')->createTag(new NewTag('v1.0.1', 'main'));

    expect($tag->sha)->toBe($sha);
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request['ref'] === 'refs/tags/v1.0.1'
        && $request['sha'] === $sha);
});

it('tags a full commit sha without looking it up', function () {
    $sha = str_repeat('c', 40);

    Http::fake(['*/repos/o/r/git/refs' => Http::response(['object' => ['sha' => $sha]])]);

    github()->createTag('o/r', new NewTag('v3', $sha));

    Http::assertSentCount(1);
});

it('creates and deletes a webhook', function () {
    Http::fake([
        '*/repos/o/r/hooks/9' => Http::response([], 204),
        '*/repos/o/r/hooks' => Http::response(['id' => 9, 'config' => ['url' => 'https://hook'], 'events' => ['push'], 'active' => true]),
    ]);

    expect(github()->createWebhook('o/r', new NewWebhook('https://hook', ['push'], 'secret')))
        ->toBeInstanceOf(Webhook::class)->id->toBe('9');

    github()->deleteWebhook('o/r', '9');

    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/hooks/9'));
});

it('guards writes behind authentication', function () {
    Git::provider(ProviderName::Github)
        ->createRepository(new NewRepository('x'));
})->throws(InvalidCredentialsException::class);

it('surfaces upstream 422 errors as http exceptions', function () {
    Http::fake(['*/user/repos' => Http::response(['message' => 'Validation Failed'], 422)]);

    github()->createRepository(new NewRepository('dup'));
})->throws(RequestException::class);

it('records rate limit status from response headers', function () {
    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'o'], 200, [
        'X-RateLimit-Limit' => '5000',
        'X-RateLimit-Remaining' => '4999',
        'X-RateLimit-Used' => '1',
        'X-RateLimit-Reset' => (string) Carbon::parse('2030-01-01')->timestamp,
    ])]);

    $github = github();
    $github->user();

    expect($github->rateLimit())->not->toBeNull()
        ->limit->toBe(5000)->remaining->toBe(4999)->used->toBe(1);
});
