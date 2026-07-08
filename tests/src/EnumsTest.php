<?php

declare(strict_types=1);

use RoundlyConsulting\Enums\DataTransferObjects\EnumOption;
use RoundlyConsulting\Git\Dto\FeatureInfo;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Providers\Bitbucket;
use RoundlyConsulting\Git\Providers\Github;
use RoundlyConsulting\Git\Providers\Gitlab;

it('maps provider names to keys, labels, classes and base urls', function (ProviderName $name, string $key, string $label, string $class, string $url) {
    expect($name->key())->toBe($key)
        ->and($name->label())->toBe($label)
        ->and($name->providerClass())->toBe($class)
        ->and($name->apiBaseUrl())->toBe($url);
})->with([
    'github' => [ProviderName::Github, 'github', 'GitHub', Github::class, 'https://api.github.com'],
    'gitlab' => [ProviderName::Gitlab, 'gitlab', 'GitLab', Gitlab::class, 'https://gitlab.com'],
    'bitbucket' => [ProviderName::Bitbucket, 'bitbucket', 'Bitbucket', Bitbucket::class, 'https://api.bitbucket.org'],
]);

it('keeps the vendor-cased label over the enum helper alias', function () {
    // The domain label() shadows the Helpers trait alias so proper casing wins.
    expect(ProviderName::Github->label())->toBe('GitHub')
        ->and(ProviderName::Github->readable())->toBe('Github');
});

it('exposes the enum helper surface on provider names', function () {
    expect(ProviderName::values()->all())->toBe(['github', 'gitlab', 'bitbucket'])
        ->and(ProviderName::names()->all())->toBe(['Github', 'Gitlab', 'Bitbucket'])
        ->and(ProviderName::fromName('Gitlab'))->toBe(ProviderName::Gitlab)
        ->and(ProviderName::hasValue('github'))->toBeTrue()
        ->and(ProviderName::options()->first())->toBeInstanceOf(EnumOption::class);
});

it('exposes the enum helper surface on features', function () {
    expect(Feature::values())->toContain('repositories', 'contents', 'create_webhook')
        ->and(Feature::validationRule())->toStartWith('in:repositories,repository,commits')
        ->and(Feature::options())->toHaveCount(count(Feature::cases()))
        ->and(Feature::tryFromName('ListCommits'))->toBe(Feature::ListCommits)
        ->and(Feature::hasValue('search_repositories'))->toBeTrue();
});

it('exposes a description and info dto for every feature', function () {
    foreach (Feature::cases() as $feature) {
        expect($feature->description())->toBeString()->not->toBe('')
            ->and($feature->info())->toBeInstanceOf(FeatureInfo::class)
            ->and($feature->info()->id)->toBe($feature);
    }
});

it('serializes a feature info dto with the enum value', function () {
    expect(Feature::ListCommits->info()->toArray())
        ->toBe(['id' => 'commits', 'description' => Feature::ListCommits->description(), 'supported' => true]);
});
