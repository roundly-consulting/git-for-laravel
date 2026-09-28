<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\FeatureInfo;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Facades\Git;

it('reports every feature in the capability matrix', function () {
    $capabilities = github()->capabilities();

    expect(array_keys($capabilities))->toBe(array_map(fn (Feature $f) => $f->value, Feature::cases()))
        ->and($capabilities[Feature::CreateRelease->value])->toBeTrue();
});

it('answers supportsAll and supportsAny', function () {
    expect(github()->supportsAll(Feature::CreateRelease, Feature::CreateTag))->toBeTrue()
        ->and(github()->supportsAll(Feature::CreateRelease, Feature::Languages))->toBeTrue()
        ->and(bitbucket()->supportsAny(Feature::Languages))->toBeFalse()
        ->and(bitbucket()->supportsAll(Feature::Languages, Feature::FindRepository))->toBeFalse()
        ->and(bitbucket()->supportsAny(Feature::FindRepository, Feature::Languages))->toBeTrue();
});

it('builds a flagged feature matrix', function () {
    $matrix = collect(bitbucket()->featureMatrix());

    $languages = $matrix->firstOrFail(fn (FeatureInfo $info) => $info->id === Feature::Languages);
    $findRepo = $matrix->firstOrFail(fn (FeatureInfo $info) => $info->id === Feature::FindRepository);

    expect($languages->supported)->toBeFalse()
        ->and($findRepo->supported)->toBeTrue()
        ->and($matrix)->toHaveCount(count(Feature::cases()));
});

it('exposes capabilities from the manager without authenticating', function () {
    $caps = Git::capabilities(ProviderName::Bitbucket);

    expect($caps[Feature::Languages->value])->toBeFalse()
        ->and($caps[Feature::FindRepository->value])->toBeTrue();
});

it('reports installation features on github only', function () {
    expect(github()->supportsAll(
        Feature::FindInstallation,
        Feature::ListInstallations,
        Feature::ListInstallationRepositories,
    ))->toBeTrue()
        ->and(gitlab()->supportsAny(Feature::FindInstallation, Feature::ListInstallationRepositories))->toBeFalse()
        ->and(bitbucket()->supportsAny(Feature::FindInstallation, Feature::ListInstallationRepositories))->toBeFalse();
});
