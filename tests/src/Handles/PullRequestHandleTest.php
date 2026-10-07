<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Input\NewReview;
use RoundlyConsulting\Git\Enums\MergeMethod;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ResourceState;
use RoundlyConsulting\Git\Enums\ReviewEvent;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\Handles\PullRequestHandle;

it('drives one pull request through its handle', function (): void {
    fakeCredentials();

    $fake = Git::fake();
    $fake->github()->seedMergeCommit('merge-sha');

    $pr = Git::github()->repo('acme/app')->pullRequest(12);

    expect($pr->number())->toBe(12)
        ->and($pr->get()->number)->toBe(12)
        ->and($pr->merge(MergeMethod::Squash, sha: 'head-sha', title: 'Ship it'))->toBe('merge-sha')
        ->and($pr->approve('LGTM'))->toBe('APPROVED')
        ->and($pr->review(new NewReview(ReviewEvent::Comment, 'Two findings.'))->state)->toBe('COMMENTED')
        ->and($pr->reviews()->isEmpty())->toBeTrue()
        ->and($pr->close()->state)->toBe(ResourceState::Closed)
        ->and($pr->comment('Thanks!')->body)->toBe('Thanks!');

    $scoped = fn (string $path, int $number): bool => $path === 'acme/app' && $number === 12;

    $fake->assertSent(ProviderName::Github, 'pullRequest', $scoped);
    $fake->assertSent(ProviderName::Github, 'mergePullRequest', fn (string $path, int $number, MergeMethod $method, ?string $sha, ?string $title): bool => $scoped($path, $number)
        && $method === MergeMethod::Squash && $sha === 'head-sha' && $title === 'Ship it');
    $fake->assertSent(ProviderName::Github, 'approvePullRequest', fn (string $path, int $number, ?string $body): bool => $scoped($path, $number) && $body === 'LGTM');
    $fake->assertSent(ProviderName::Github, 'reviewPullRequest', $scoped);
    $fake->assertSent(ProviderName::Github, 'pullRequestReviews', fn (string $path, int $number, int $perPage, int $maxPages): bool => $scoped($path, $number)
        && $perPage === 100 && $maxPages === 5);
    $fake->assertSent(ProviderName::Github, 'closePullRequest', $scoped);
    $fake->assertSent(ProviderName::Github, 'comment', fn (string $path, $comment): bool => $path === 'acme/app' && $comment->number === 12);
});

it('merges against the real endpoint', function (): void {
    Http::fake(['*/repos/acme/app/pulls/7/merge' => Http::response(['merged' => true, 'sha' => 'deadbeef'])]);

    expect(github()->repo('acme/app')->pullRequest(7)->merge())->toBe('deadbeef');
});

it('answers the provider refusal for a write the forge does not support', function (): void {
    expect(fn () => gitlab()->repo('group/project')->pullRequest(1)->merge())->toThrow(FeatureNotSupportedException::class);
});

it('refuses a pull request number below one', function (int $number): void {
    Git::fake();

    Git::github()->repo('acme/app')->pullRequest($number);
})->with([0, -3])->throws(OutOfScopeException::class, 'Pull request numbers start at 1');

it('refuses a path that could step outside the repository', function (): void {
    new PullRequestHandle(Git::fake()->github(), 'acme/../x', 1);
})->throws(OutOfScopeException::class);
