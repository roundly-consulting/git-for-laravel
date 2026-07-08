<?php

declare(strict_types=1);

use RoundlyConsulting\Enums\DataTransferObjects\EnumOption;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ResourceState;

it('normalizes github states', function () {
    expect(ResourceState::fromProvider(ProviderName::Github, 'open'))->toBe(ResourceState::Open)
        ->and(ResourceState::fromProvider(ProviderName::Github, 'closed'))->toBe(ResourceState::Closed)
        ->and(ResourceState::fromProvider(ProviderName::Github, 'merged'))->toBe(ResourceState::Merged)
        ->and(ResourceState::fromProvider(ProviderName::Github, 'weird'))->toBe(ResourceState::Unknown);
});

it('normalizes gitlab states', function () {
    expect(ResourceState::fromProvider(ProviderName::Gitlab, 'opened'))->toBe(ResourceState::Open)
        ->and(ResourceState::fromProvider(ProviderName::Gitlab, 'merged'))->toBe(ResourceState::Merged)
        ->and(ResourceState::fromProvider(ProviderName::Gitlab, 'locked'))->toBe(ResourceState::Closed)
        ->and(ResourceState::fromProvider(ProviderName::Gitlab, 'closed'))->toBe(ResourceState::Closed);
});

it('normalizes bitbucket states', function () {
    expect(ResourceState::fromProvider(ProviderName::Bitbucket, 'OPEN'))->toBe(ResourceState::Open)
        ->and(ResourceState::fromProvider(ProviderName::Bitbucket, 'MERGED'))->toBe(ResourceState::Merged)
        ->and(ResourceState::fromProvider(ProviderName::Bitbucket, 'DECLINED'))->toBe(ResourceState::Closed)
        ->and(ResourceState::fromProvider(ProviderName::Bitbucket, 'SUPERSEDED'))->toBe(ResourceState::Closed)
        ->and(ResourceState::fromProvider(ProviderName::Bitbucket, 'mystery'))->toBe(ResourceState::Unknown);
});

it('exposes a human label through the enum helper trait', function () {
    expect(ResourceState::Open->label())->toBe('Open')
        ->and(ResourceState::Merged->label())->toBe('Merged')
        ->and(ResourceState::Draft->label())->toBe('Draft')
        ->and(ResourceState::Unknown->label())->toBe('Unknown')
        ->and(ResourceState::Closed->label())->toBe('Closed')
        ->and(ResourceState::Open->readable())->toBe('Open');
});

it('exposes the enum helper option surface', function () {
    expect(ResourceState::values()->all())->toBe(['open', 'closed', 'merged', 'draft', 'unknown'])
        ->and(ResourceState::options())->toHaveCount(5)
        ->and(ResourceState::options()->first())->toBeInstanceOf(EnumOption::class);
});
