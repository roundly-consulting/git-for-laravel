<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\FeatureInfo;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\Timespan;
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

it('maps timespans to seconds', function (Timespan $timespan, int $seconds) {
    expect($timespan->seconds())->toBe($seconds);
})->with([
    [Timespan::Second, 1],
    [Timespan::Minute, 60],
    [Timespan::Hour, 3600],
    [Timespan::Day, 86400],
]);

it('resolves a timespan from a string', function () {
    expect(Timespan::tryFromString('hour'))->toBe(Timespan::Hour)
        ->and(Timespan::tryFromString('decade'))->toBeNull();
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
