<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Comparison;
use RoundlyConsulting\Git\Dto\Contributor;
use RoundlyConsulting\Git\Dto\Credentials\OauthToken;
use RoundlyConsulting\Git\Dto\Credentials\Token;
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
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\PullRequestReview;
use RoundlyConsulting\Git\Dto\PullRequestReviewComment;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\DiffSide;
use RoundlyConsulting\Git\Enums\MergeMethod;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ResourceState;
use RoundlyConsulting\Git\Enums\ReviewEvent;
use RoundlyConsulting\Git\Facades\Registry;

/**
 * The fake used to answer roughly a third of the provider surface, so a host application
 * that listed pull requests, read file contents, or merged anything through
 * `Registry::fake()` hit PHP's "undefined method" — a failure that reads as a bug in the
 * host. These pin the whole surface.
 */
function seededPullRequest(int $number = 7, ResourceState $state = ResourceState::Open): PullRequest
{
    return new PullRequest(
        provider: ProviderName::Github,
        id: 'pr-1',
        number: $number,
        title: 'Add CI',
        body: 'body',
        state: $state,
        sourceBranch: 'feature',
        targetBranch: 'main',
        author: null,
        url: 'https://fake/pr/7',
        createdAt: Carbon::parse('2026-01-01'),
    );
}

it('answers every list read from its seed bucket', function (): void {
    $provider = Registry::fake()->github();

    $provider->seedBranches(['main', 'develop'])
        ->seedPullRequests([seededPullRequest()])
        ->seedIssues([new Issue(ProviderName::Github, 'i-1', 3, 'Bug', null, ResourceState::Open, null, null, Carbon::now())])
        ->seedTags([new Tag(ProviderName::Github, 'v1.0.0', 'sha', null)])
        ->seedReleases([new Release(ProviderName::Github, 'r-1', 'v1.0.0', 'One', null, false, false, null, Carbon::now())])
        ->seedContributors([new Contributor('1', 'octocat', null, 12)])
        ->seedLanguages(['PHP' => 900]);

    expect($provider->branches('o/r')->items)->toBe(['main', 'develop'])
        ->and($provider->pullRequests('o/r')->items)->toHaveCount(1)
        ->and($provider->issues('o/r')->items)->toHaveCount(1)
        ->and($provider->tags('o/r')->items)->toHaveCount(1)
        ->and($provider->releases('o/r')->items)->toHaveCount(1)
        ->and($provider->contributors('o/r')->items)->toHaveCount(1)
        ->and($provider->languages('o/r'))->toBe(['PHP' => 900]);
});

it('answers an EMPTY page for a list nobody seeded', function (): void {
    // An empty list is a real provider answer, so it cannot mislead — unlike a
    // placeholder resource, which turns a missing seed into a confusing assertion failure.
    $provider = Registry::fake()->github();

    expect($provider->pullRequests('o/r')->items)->toBe([])
        ->and($provider->issues('o/r')->items)->toBe([])
        ->and($provider->tags('o/r')->items)->toBe([])
        ->and($provider->releases('o/r')->items)->toBe([])
        ->and($provider->contributors('o/r')->items)->toBe([])
        ->and($provider->branches('o/r')->items)->toBe([])
        ->and($provider->languages('o/r'))->toBe([]);
});

it('falls back from a single resource to the first of its list', function (): void {
    $provider = Registry::fake()->github();

    $provider->seedPullRequests([seededPullRequest()])
        ->seedIssues([new Issue(ProviderName::Github, 'i-1', 3, 'Bug', null, ResourceState::Open, null, null, Carbon::now())])
        ->seedReleases([new Release(ProviderName::Github, 'r-1', 'v1.0.0', 'One', null, false, false, null, Carbon::now())]);

    expect($provider->pullRequest('o/r', 7)->number)->toBe(7)
        ->and($provider->issue('o/r', 3)->number)->toBe(3)
        ->and($provider->release('o/r', 'v1.0.0')->tagName)->toBe('v1.0.0');
});

it('names the seeder when a single resource is missing', function (): void {
    $provider = Registry::fake()->github();

    expect(fn () => $provider->contents('o/r', 'README.md'))
        ->toThrow(RuntimeException::class, 'seedContents()')
        ->and(fn () => $provider->issue('o/r', 1))->toThrow(RuntimeException::class, 'seedIssue()')
        ->and(fn () => $provider->release('o/r', 'v1'))->toThrow(RuntimeException::class, 'seedRelease()')
        ->and(fn () => $provider->repository('o/r'))->toThrow(RuntimeException::class, 'seedRepository()');
});

it('reads seeded file contents and a comparison', function (): void {
    $provider = Registry::fake()->github();

    $provider->seedContents(new FileContent('README.md', '# Hi', 'sha', 4, null));

    expect($provider->contents('o/r', 'README.md')->content)->toBe('# Hi')
        // No comparison seeded: an empty diff between the two refs asked for, which is a
        // real answer rather than a stand-in.
        ->and($provider->compare('o/r', 'main', 'feature'))
        ->base->toBe('main')
        ->head->toBe('feature')
        ->files->toBe([]);
});

it('runs a commit query over the seeded commits', function (): void {
    $registry = Registry::fake();
    $provider = $registry->github();

    $provider->seedCommits([new Commit(ProviderName::Github, 'sha', 'msg', new Author('n', 'e', null), null, Carbon::now())]);

    expect($provider->commits('o/r')->branch('main')->collect())->toHaveCount(1);

    $registry->assertSent(ProviderName::Github, 'commits');
});

it('synthesizes every write from the input it was handed', function (): void {
    $registry = Registry::fake();
    $provider = $registry->github();

    $commit = $provider->createFile('o/r', new NewFile('a.txt', 'x', 'add a', 'main'));
    $updated = $provider->updateFile('o/r', new UpdatedFile('a.txt', 'y', 'update a', 'main', 'sha'));

    expect($provider->createBranch('o/r', new NewBranch('feature', 'main')))->toBe('refs/heads/feature')
        ->and($commit->message)->toBe('add a')
        ->and($updated->message)->toBe('update a')
        ->and($provider->createPullRequest('o/r', new NewPullRequest('Add CI', 'feature', 'main', 'body')))
        ->title->toBe('Add CI')
        ->state->toBe(ResourceState::Open)
        ->and($provider->comment('o/r', new NewComment(7, 'nice'))->body)->toBe('nice')
        ->and($provider->createRelease('o/r', new NewRelease('v1.0.0', 'One'))->tagName)->toBe('v1.0.0')
        ->and($provider->createTag('o/r', new NewTag('v1.0.0', 'sha'))->name)->toBe('v1.0.0');

    $registry->assertSent(ProviderName::Github, 'createBranch');
    $registry->assertSent(ProviderName::Github, 'createFile');
    $registry->assertSent(ProviderName::Github, 'updateFile');
    $registry->assertSent(ProviderName::Github, 'createPullRequest');
    $registry->assertSent(ProviderName::Github, 'comment');
    $registry->assertSent(ProviderName::Github, 'createRelease');
    $registry->assertSent(ProviderName::Github, 'createTag');
});

it('drives the close / approve / merge flow', function (): void {
    $registry = Registry::fake();
    $provider = $registry->github();

    $provider->seedPullRequest(seededPullRequest())->seedMergeCommit('merge-sha');

    expect($provider->approvePullRequest('o/r', 7, 'Looks good.'))->toBe('APPROVED')
        ->and($provider->mergePullRequest('o/r', 7, MergeMethod::Squash))->toBe('merge-sha')
        // The seeded pull request comes back CLOSED: a host asserting the state after a
        // close is asserting the one thing the method exists to do.
        ->and($provider->closePullRequest('o/r', 7))
        ->state->toBe(ResourceState::Closed)
        ->number->toBe(7)
        ->title->toBe('Add CI');

    $registry->assertSent(ProviderName::Github, 'approvePullRequest');
    $registry->assertSent(ProviderName::Github, 'mergePullRequest');
    $registry->assertSent(ProviderName::Github, 'closePullRequest');
});

it('answers a submitted review with the state the event actually means', function (): void {
    $registry = Registry::fake();
    $provider = $registry->github();

    // Derived from the event rather than seeded: a fake that answered APPROVED for a
    // COMMENT review would pass a test of the exact confusion the verdict-in-the-body
    // design exists to avoid.
    expect($provider->reviewPullRequest('o/r', 7, new NewReview(
        event: ReviewEvent::Comment,
        body: '**Verdict: changes_requested**',
        comments: [new NewReviewComment('app/Foo.php', 42, 'This nulls out.')],
    )))->state->toBe('COMMENTED')
        ->body->toBe('**Verdict: changes_requested**')
        ->and($provider->reviewPullRequest('o/r', 7, new NewReview(ReviewEvent::Approve)))
        ->state->toBe('APPROVED');

    $registry->assertSent(ProviderName::Github, 'reviewPullRequest');
});

it('reads back the reviews and comments it was seeded, and an empty pair when it was not', function (): void {
    $provider = Registry::fake()->github();

    expect($provider->pullRequestReviews('o/r', 7))->reviews->toBe([])->comments->toBe([]);

    $provider->seedPullRequestReviews(
        [new PullRequestReview(
            provider: ProviderName::Github,
            id: '11',
            state: 'COMMENTED',
            body: 'Two findings.',
            author: new Author(name: 'reviewer', email: '', avatar: null),
            url: null,
            submittedAt: Carbon::now(),
        )],
        [new PullRequestReviewComment(
            provider: ProviderName::Github,
            id: '21',
            body: 'This nulls out.',
            path: 'app/Foo.php',
            line: 42,
            side: DiffSide::Right,
            author: null,
            url: null,
            createdAt: Carbon::now(),
        )],
    );

    expect($provider->pullRequestReviews('o/r', 7))
        ->reviews->toHaveCount(1)
        ->comments->toHaveCount(1);
});

it('seeds the two halves separately and still joins them', function (): void {
    // The join the real provider does across two endpoints has to hold on the fake too,
    // or a host asserting "changes requested, because of THESE findings" passes here and
    // fails against GitHub.
    $provider = Registry::fake()->github();

    $provider->seedPullRequestReviews(
        [new PullRequestReview(
            provider: ProviderName::Github,
            id: '12',
            state: 'CHANGES_REQUESTED',
            body: 'One blocker.',
            author: null,
            url: null,
            submittedAt: Carbon::now(),
        )],
        [new PullRequestReviewComment(
            provider: ProviderName::Github,
            id: '21',
            body: 'Blocking.',
            path: 'app/Foo.php',
            line: 42,
            side: DiffSide::Right,
            author: null,
            url: null,
            createdAt: Carbon::now(),
            reviewId: '12',
        )],
    );

    $reviews = $provider->pullRequestReviews('o/r', 7);

    expect($reviews->latest()?->state)->toBe('CHANGES_REQUESTED')
        ->and($reviews->commentsFor('12'))->toHaveCount(1);
});

it('answers a seeded review instead of one synthesized from the input', function (): void {
    // The escape hatch for a host that needs a specific id or url back — it OVERRIDES the
    // event-derived state, which is the whole point of seeding one.
    $provider = Registry::fake()->github();

    $provider->seedSubmittedReview(new PullRequestReview(
        provider: ProviderName::Github,
        id: 'seeded',
        state: 'DISMISSED',
        body: null,
        author: null,
        url: 'https://github.test/r/1',
        submittedAt: Carbon::now(),
    ));

    expect($provider->reviewPullRequest('o/r', 7, new NewReview(ReviewEvent::Approve)))
        ->id->toBe('seeded')
        ->state->toBe('DISMISSED');
});

it('merges and approves without any seeding at all', function (): void {
    $provider = Registry::fake()->github();

    expect($provider->mergePullRequest('o/r', 7))->toBe('fake-merge-sha')
        ->and($provider->approvePullRequest('o/r', 7))->toBe('APPROVED')
        ->and($provider->closePullRequest('o/r', 7)->state)->toBe(ResourceState::Closed);
});

it('answers a search from the repository bucket when no results were seeded', function (): void {
    $provider = Registry::fake()->github();

    $provider->seedRepositories([new Repository(
        provider: ProviderName::Github,
        id: '1',
        path: 'acme/api',
        name: 'api',
        description: null,
        defaultBranch: 'main',
        owner: new Owner(id: '1', name: 'acme', avatar: null),
        createdAt: Carbon::now(),
        lastActivityAt: Carbon::now(),
    )]);

    expect($provider->searchRepositories('api')->items)->toHaveCount(1);

    $provider->seedSearchResults([]);

    // An explicitly seeded (empty) result set wins over the fallback.
    expect($provider->searchRepositories('api')->items)->toBe([]);
});

it('omits an EMPTY state from the fake install url, like the real provider does', function (): void {
    $provider = Registry::fake()->github();

    // A consumer that built a state and got back '' would otherwise be able to assert a
    // `?state=` URL production never produces.
    expect($provider->installUrl(''))->not->toContain('?state=')
        ->and($provider->installUrl(null))->not->toContain('?state=')
        ->and($provider->installUrl('abc'))->toContain('?state=abc');
});

it('lets an explicit seed win over every synthesized default', function (): void {
    $provider = Registry::fake()->github();

    $repository = new Repository(
        provider: ProviderName::Github,
        id: '9',
        path: 'acme/seeded',
        name: 'seeded',
        description: null,
        defaultBranch: 'main',
        owner: new Owner(id: '1', name: 'acme', avatar: null),
        createdAt: Carbon::now(),
        lastActivityAt: Carbon::now(),
    );

    $provider->seedCreatedRepository($repository)
        ->seedCreatedWebhook(new Webhook(ProviderName::Github, '42', 'https://seeded.test/hook', ['push'], true))
        ->seedIssue(new Issue(ProviderName::Github, 'i-9', 99, 'Seeded', null, ResourceState::Closed, null, null, Carbon::now()))
        ->seedRelease(new Release(ProviderName::Github, 'r-9', 'v9.9.9', 'Nine', null, false, false, null, Carbon::now()))
        ->seedComparison(new Comparison('main', 'feature', 3, 1, []))
        ->seedComment(new Comment('c-9', 'seeded body', null, null, Carbon::now()))
        ->seedApprovalState('CHANGES_REQUESTED');

    expect($provider->createRepository(new NewRepository('ignored'))->path)->toBe('acme/seeded')
        ->and($provider->createWebhook('o/r', new NewWebhook('https://ignored.test/hook'))->id)->toBe('42')
        ->and($provider->issue('o/r', 1)->number)->toBe(99)
        ->and($provider->release('o/r', 'v1')->tagName)->toBe('v9.9.9')
        ->and($provider->compare('o/r', 'main', 'feature')->aheadBy)->toBe(3)
        ->and($provider->comment('o/r', new NewComment(1, 'ignored'))->body)->toBe('seeded body')
        // A review that did NOT approve is a real GitHub answer, and a host that models
        // it needs the fake to be able to say so.
        ->and($provider->approvePullRequest('o/r', 7))->toBe('CHANGES_REQUESTED')
        ->and($provider->featureInfo())->toEqual($provider->featureMatrix());
});

it('carries a refreshable credential into the fake clone url', function (): void {
    $provider = Registry::fake()->github();

    $oauth = OauthToken::for('access', 'refresh', 'client', 'secret', 'https://token.test');

    expect($provider->cloneUrlForRepository('o/r', 'jane', $oauth))
        ->toBe('https://jane:fake-refreshed@fake/o/r.git')
        // An empty static secret drops the password rather than emitting `user:@host`,
        // which some git clients read as a prompt.
        ->and($provider->cloneUrlForRepository('o/r', 'jane', Token::from('')))
        ->toBe('https://jane@fake/o/r.git');
});
