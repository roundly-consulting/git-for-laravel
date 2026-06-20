<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Facades\Registry;

function fakeRepository(string $name = 'Hello-World'): Repository
{
    return new Repository(
        id: '1',
        path: "octocat/{$name}",
        name: $name,
        description: null,
        defaultBranch: 'main',
        owner: new Owner(id: '1', name: 'octocat', avatar: null),
        createdAt: Carbon::now(),
        lastActivityAt: Carbon::now(),
    );
}

it('returns seeded repositories and records the call', function () {
    $fake = Registry::fake();
    $fake->github()->seedRepositories([fakeRepository()]);

    $repositories = Registry::github()->repositories();

    expect($repositories->first())->toBeInstanceOf(Repository::class)->name->toBe('Hello-World');

    $fake->assertSent(ProviderName::Github, 'repositories');
    $fake->assertNotSent(ProviderName::Github, 'createRepository');
});

it('seeds a user and a single repository', function () {
    $fake = Registry::fake();
    $fake->github()
        ->seedUser(new Owner(id: '9', name: 'jane', avatar: null))
        ->seedRepository(fakeRepository('one'));

    expect(Registry::github()->user()->name)->toBe('jane')
        ->and(Registry::github()->repository('octocat/one')->name)->toBe('one');
});

it('records and asserts created repositories', function () {
    $fake = Registry::fake();

    Registry::github()->createRepository(new NewRepository('acme'));

    $fake->assertRepositoryCreated('acme');
    $fake->assertSent(ProviderName::Github, 'createRepository');
});

it('resolves fakes via the provider method and lazily', function () {
    $fake = Registry::fake();
    $fake->fakeFor(ProviderName::Gitlab)->seedRepositories([fakeRepository()]);

    expect(Registry::provider('gitlab')->allRepositories()->count())->toBe(1);
});

it('asserts nothing sent on a fresh fake', function () {
    Registry::fake()->assertNothingSent();
});

it('fails assertSent when the method was never called', function () {
    $fake = Registry::fake();

    expect(fn () => $fake->assertSent(ProviderName::Bitbucket, 'repositories'))
        ->toThrow(AssertionFailedError::class);
});
