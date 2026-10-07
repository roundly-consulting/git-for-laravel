<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Enums\CommentTarget;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\MergeMethod;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\Providers\BaseProvider;

/*
 * A fake that supports more than the forge it stands in for lets a host test pass against
 * a flow production refuses. Every fake driver reports — and enforces — the feature matrix
 * of the real driver it doubles.
 */

it('reports the real driver feature matrix on every fake driver', function (ProviderName $name) {
    $fake = Git::fake()->fakeFor($name);

    /** @var BaseProvider $real */
    $real = app($name->providerClass());

    expect($fake->features())->toBe($real->features())
        ->and($fake->capabilities())->toBe($real->capabilities())
        ->and($fake->featureMatrix())->toEqual($real->featureMatrix())
        ->and($fake->featureInfo())->toEqual($real->featureInfo())
        ->and($fake->supportsAll(Feature::MergePullRequest, Feature::ListCommits))->toBe($real->supportsAll(Feature::MergePullRequest, Feature::ListCommits))
        ->and($fake->supportsAny(Feature::MergePullRequest, Feature::Languages))->toBe($real->supportsAny(Feature::MergePullRequest, Feature::Languages));
})->with([ProviderName::Github, ProviderName::Gitlab, ProviderName::Bitbucket]);

it('refuses a bitbucket merge on the fake the way the real driver does', function () {
    $fake = Git::fake();

    expect(Git::bitbucket()->supports(Feature::MergePullRequest))->toBeFalse()
        ->and(fn () => Git::bitbucket()->repo('acme/app')->pullRequest(1)->merge(MergeMethod::Squash))
        ->toThrow(FeatureNotSupportedException::class, 'Feature [merge_pull_request] is not supported by provider [Bitbucket].');

    // The refused call never reached the forge, so it is not recorded as sent.
    $fake->assertNotSent(ProviderName::Bitbucket, 'mergePullRequest');
});

it('refuses every operation the faked forge lacks', function (ProviderName $name, Closure $call) {
    Git::fake();

    expect(fn () => $call(Git::provider($name)))->toThrow(FeatureNotSupportedException::class);
})->with([
    'gitlab close' => [ProviderName::Gitlab, fn ($p) => $p->closePullRequest('g/p', 1)],
    'gitlab approve' => [ProviderName::Gitlab, fn ($p) => $p->approvePullRequest('g/p', 1)],
    'gitlab reviews' => [ProviderName::Gitlab, fn ($p) => $p->pullRequestReviews('g/p', 1)],
    'gitlab installations' => [ProviderName::Gitlab, fn ($p) => $p->installations()->all()],
    'gitlab install url' => [ProviderName::Gitlab, fn ($p) => $p->installations()->installUrl()],
    'gitlab template' => [ProviderName::Gitlab, fn ($p) => $p->createRepository(new NewRepository(name: 'x', template: 'acme/tpl'))],
    'bitbucket issues' => [ProviderName::Bitbucket, fn ($p) => $p->repo('a/b')->issues()],
    'bitbucket contents' => [ProviderName::Bitbucket, fn ($p) => $p->repo('a/b')->contents('README.md')],
    'bitbucket tags' => [ProviderName::Bitbucket, fn ($p) => $p->repo('a/b')->tags()],
    'bitbucket batch languages' => [ProviderName::Bitbucket, fn ($p) => $p->batch()->languages(['a/b'])],
    'bitbucket batch contents' => [ProviderName::Bitbucket, fn ($p) => $p->batch()->contents('a/b', ['README.md'])],
]);

it('still drives every supported operation on the fake', function () {
    $fake = Git::fake();

    expect(Git::github()->repo('acme/app')->pullRequest(1)->merge())->toBe('fake-merge-sha')
        ->and(Git::gitlab()->repo('g/p')->issues()->items)->toBe([])
        ->and(Git::bitbucket()->repo('a/b')->pullRequests()->items)->toBe([]);

    $fake->assertSent(ProviderName::Github, 'mergePullRequest');
});

/** The exception a call ends in, or null when it returns. */
function thrownBy(Closure $call): ?Throwable
{
    try {
        $call();
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
}

it('refuses on the fake every input the real driver refuses, before recording it', function (ProviderName $name, string $method, Closure $call) {
    Http::fake();

    $real = thrownBy(fn () => $call(Git::provider($name, Token::from('t'))));

    $fake = Git::fake();
    $faked = thrownBy(fn () => $call(Git::provider($name, Token::from('t'))));

    expect($real)->not->toBeNull()
        ->and($faked)->toBeInstanceOf($real::class)
        ->and($faked?->getMessage())->toBe($real->getMessage());

    $fake->assertNotSent($name, $method);
    Http::assertNothingSent();
})->with([
    'bitbucket commit date filter' => [ProviderName::Bitbucket, 'commits.get', fn ($p) => $p->repo('a/b')->commits()->since('2026-01-01')->get()],
    'gitlab comment with no target' => [ProviderName::Gitlab, 'comment', fn ($p) => $p->comment('g/p', new NewComment(3, 'hi'))],
    'bitbucket issue comment' => [ProviderName::Bitbucket, 'comment', fn ($p) => $p->comment('a/b', new NewComment(3, 'hi', CommentTarget::Issue))],
    'bitbucket initial commit' => [ProviderName::Bitbucket, 'createRepository', fn ($p) => $p->createRepository(new NewRepository(name: 'x', owner: 'ws', autoInit: true))],
    'gitlab named owner' => [ProviderName::Gitlab, 'createRepository', fn ($p) => $p->createRepository(new NewRepository(name: 'x', owner: 'acme'))],
    'gitlab inactive hook' => [ProviderName::Gitlab, 'createWebhook', fn ($p) => $p->createWebhook('g/p', new NewWebhook('https://app.test/hook', ['push'], active: false))],
    'gitlab unknown hook event' => [ProviderName::Gitlab, 'createWebhook', fn ($p) => $p->createWebhook('g/p', new NewWebhook('https://app.test/hook', ['bogus']))],
]);
