<?php

declare(strict_types=1);

use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Git\Batch\BatchResult;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Facades\Git;

it('records batch calls and returns seeded results', function () {
    $fake = Git::fake();
    $fake->github()->seedBatch('languages', new BatchResult(['acme/api' => ['PHP' => 100]], []));

    $result = $fake->github()->batch()->languages(['acme/api']);

    expect($result->get('acme/api'))->toBe(['PHP' => 100]);

    $fake->assertBatched(ProviderName::Github, 'languages');
});

it('returns an empty result when no batch is seeded', function () {
    $fake = Git::fake();

    $result = $fake->github()->batch()->repositories(['acme/api']);

    expect($result->count())->toBe(0);

    $fake->assertBatched(ProviderName::Github, 'repositories');
});

it('fails the assertion when a batch was not sent', function () {
    $fake = Git::fake();

    expect(fn () => $fake->assertBatched(ProviderName::Github, 'languages'))
        ->toThrow(AssertionFailedError::class);
});

it('records contents and pull request batch calls', function () {
    $fake = Git::fake();

    $fake->github()->batch()->contents('acme/api', ['README.md']);
    $fake->github()->batch()->pullRequest(['main' => 'acme/api#7']);

    $fake->assertBatched(ProviderName::Github, 'contents');
    $fake->assertBatched(ProviderName::Github, 'pullRequest');
});
