<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\Input\NewReview;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\ReviewEvent;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Interfaces\Provider;
use RoundlyConsulting\Git\Providers\BaseProvider;
use RoundlyConsulting\Git\Testing\ProviderFake;

/**
 * The rule this pins: anything `BaseProvider` answers is part of the CONTRACT.
 *
 * A method that lives on the base class but not on the interface is invisible to the
 * `Provider` type, exempt from the fake, and — the way the pull-request writes shipped —
 * can be missing from the base class entirely without anything noticing, so calling it on
 * GitLab is PHP's fatal "undefined method" rather than `FeatureNotSupportedException`.
 */
it('declares every provider method on the shared interface', function (): void {
    // `@internal` methods are the batch plumbing (URL builders, mappers, the pool runner):
    // public only so `Batch` can drive them, and deliberately NOT part of the contract.
    $onBase = collect((new ReflectionClass(BaseProvider::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method): bool => $method->isStatic()
            || $method->isConstructor()
            || preg_match('/(?:^|\*)\s*@internal\b/m', (string) $method->getDocComment()) === 1)
        ->map(fn (ReflectionMethod $method): string => $method->getName());

    $onInterface = collect((new ReflectionClass(Provider::class))->getMethods())
        ->map(fn (ReflectionMethod $method): string => $method->getName());

    expect($onBase->diff($onInterface)->values()->all())->toBe([]);
});

it('keeps the batch plumbing off the shared interface', function (): void {
    // The audit finding this pins: eight mapper / URL / pool helpers used to be on the
    // public contract, so every custom driver and the fake had to answer them.
    $onInterface = collect((new ReflectionClass(Provider::class))->getMethods())
        ->map(fn (ReflectionMethod $method): string => $method->getName());

    expect($onInterface->intersect([
        'mapResource', 'mapFileContent', 'runPool', 'repositoryUrl',
        'languagesUrl', 'pullRequestUrl', 'contentsRequest', 'normalizeLanguages',
    ])->values()->all())->toBe([]);
});

it('answers every interface method on the fake', function (): void {
    // Guaranteed by PHP, so this is really an assertion that the fake still IMPLEMENTS
    // the interface rather than shadowing it — and that the surface is not empty.
    $onInterface = (new ReflectionClass(Provider::class))->getMethods();

    expect($onInterface)->not->toBeEmpty();

    foreach ($onInterface as $method) {
        expect(method_exists(ProviderFake::class, $method->getName()))->toBeTrue(
            "ProviderFake does not answer [{$method->getName()}]."
        );
    }
});

it('refuses an unsupported pull-request write instead of fatalling', function (): void {
    // Before the base-class stubs existed these three were declared on the GitHub driver
    // ONLY, so `Git::gitlab()->mergePullRequest(...)` was an "undefined method"
    // fatal — uncatchable by a consumer that catches this package's exceptions.
    $review = new NewReview(ReviewEvent::Comment, 'Findings.');

    expect(fn () => gitlab()->closePullRequest('g/p', 1))->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => gitlab()->approvePullRequest('g/p', 1))->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => gitlab()->mergePullRequest('g/p', 1))->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => gitlab()->reviewPullRequest('g/p', 1, $review))->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => gitlab()->pullRequestReviews('g/p', 1))->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => bitbucket()->reviewPullRequest('g/p', 1, $review))->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => bitbucket()->mergePullRequest('g/p', 1))->toThrow(FeatureNotSupportedException::class);
});

it('reports the pull-request write features on github only', function (): void {
    expect(github()->supportsAll(
        Feature::ClosePullRequest,
        Feature::ApprovePullRequest,
        Feature::ReviewPullRequest,
        Feature::ListPullRequestReviews,
        Feature::MergePullRequest,
    ))->toBeTrue()
        ->and(gitlab()->supportsAny(Feature::MergePullRequest, Feature::ApprovePullRequest))->toBeFalse()
        ->and(gitlab()->supportsAny(Feature::ReviewPullRequest, Feature::ListPullRequestReviews))->toBeFalse()
        ->and(bitbucket()->supportsAny(Feature::MergePullRequest, Feature::ApprovePullRequest))->toBeFalse()
        ->and(bitbucket()->supportsAny(Feature::ReviewPullRequest, Feature::ListPullRequestReviews))->toBeFalse();
});
