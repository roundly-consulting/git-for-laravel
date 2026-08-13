<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Credentials\Password;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Facades\Registry;

it('returns name and description of provider', function () {
    expect(bitbucket())
        ->name()->toBe('Bitbucket')
        ->description()->toBe('Bitbucket Provider');
});

it('checks whether specific feature is supported by provider', function () {
    expect(bitbucket())
        ->supports(Feature::ListRepositories)->toBeTrue()
        ->supports(Feature::Languages)->toBeFalse();
});

it('throws exception when authenticating with an unsupported method', function () {
    Registry::bitbucket(
        new Password(
            credentials: new SensitiveParameterValue('zer0day'),
        )
    );
})->throws(
    InvalidCredentialsException::class,
    'Authentication with [Password] is not supported by provider [Bitbucket]. '.
    'Supported authentication methods are [Token].'
);

it('returns currently authenticated user', function () {
    Http::fake(['*/2.0/user' => snapshot('bitbucket/user')]);

    expect(bitbucket()->user())
        ->toBeInstanceOf(Owner::class)
        ->name->toBe('john');
});

it('returns a page of repositories from bitbucket', function () {
    Http::fake([
        '*/2.0/repositories*' => Http::response([
            'values' => snapshot(name: 'bitbucket/repository', raw: true, times: 2),
        ]),
    ]);

    $repositories = bitbucket()->repositories();

    expect($repositories)
        ->toBeInstanceOf(Page::class)
        ->and($repositories->first())
        ->toBeInstanceOf(Repository::class)
        ->path->toBe('octocat/Hello-World');
});

it('returns a page of branches reading the cloud name field', function () {
    Http::fake(['*/2.0/repositories/octocat/Hello-World/refs/branches*' => snapshot('bitbucket/branches')]);

    expect(bitbucket()->branches('octocat/Hello-World')->items)->toBe(['main', 'dev']);
});

it('returns commits via the query builder', function () {
    Http::fake([
        '*/2.0/repositories/octocat/Hello-World/commits*' => Http::response([
            'values' => snapshot(name: 'bitbucket/commit', raw: true, times: 2),
        ]),
    ]);

    $commits = bitbucket()->commits('octocat/Hello-World')->branch('main')->get();

    expect($commits->first())
        ->toBeInstanceOf(Commit::class)
        ->sha->toBe('f7591a13eda445d9a9167f98eb870319f4b6c2d8');
});

it('returns single commit by hash', function () {
    Http::fake(['*/2.0/repositories/octocat/Hello-World/commit/f7591a13eda445d9a9167f98eb870319f4b6c2d8' => snapshot('bitbucket/commit')]);

    expect(bitbucket()->commit('octocat/Hello-World', 'f7591a13eda445d9a9167f98eb870319f4b6c2d8'))
        ->toBeInstanceOf(Commit::class)
        ->sha->toBe('f7591a13eda445d9a9167f98eb870319f4b6c2d8');
});

it('throws when an unsupported feature is requested', function () {
    bitbucket()->languages('octocat/Hello-World');
})->throws(FeatureNotSupportedException::class);

it('returns clone url with token auth', function () {
    $credentials = new Token(
        credentials: new SensitiveParameterValue('myToken'),
    );

    expect(bitbucket()->cloneUrlForRepository('testing/ok', 'john', $credentials))
        ->toBe('https://john:myToken@bitbucket.org/testing/ok.git');
});
