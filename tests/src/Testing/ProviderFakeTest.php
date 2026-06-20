<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Facades\Registry;

it('exposes a faithful provider double surface', function () {
    $fake = Registry::fake();
    $provider = $fake->github();

    expect($provider->name())->toBe('GitHub')
        ->and($provider->description())->toBe('GitHub Provider')
        ->and($provider->providerName())->toBe(ProviderName::Github)
        ->and($provider->isAuthenticated())->toBeTrue()
        ->and($provider->supports(Feature::ListRepositories))->toBeTrue()
        ->and($provider->features())->toBe(Feature::cases())
        ->and($provider->authenticationMethods())->toBe([])
        ->and($provider->rateLimit())->toBeNull()
        ->and($provider->authenticate(Token::from('x')))->toBe($provider);
});

it('returns seeded branches, commit and clone url', function () {
    $fake = Registry::fake();
    $commit = new Commit('sha', 'msg', new Author('n', 'e', null), null, Carbon::now());

    $provider = $fake->github();
    (fn () => $this->seeded['branches'] = ['main'])->call($provider);
    $provider->seedCommits([$commit]);
    (fn () => $this->seeded['commit'] = $commit)->call($provider);

    expect($provider->branches('o/r')->items)->toBe(['main'])
        ->and($provider->commit('o/r', 'sha')->sha)->toBe('sha')
        ->and($provider->cloneUrlForRepository('o/r', 'jane', Token::from('x')))->toBe('https://jane@fake/o/r.git')
        ->and($provider->user())->toBeInstanceOf(Owner::class);
});

it('throws when reading unseeded repository or commit', function () {
    $provider = Registry::fake()->github();

    expect(fn () => $provider->repository('o/r'))->toThrow(RuntimeException::class)
        ->and(fn () => $provider->commit('o/r', 'x'))->toThrow(RuntimeException::class);
});
