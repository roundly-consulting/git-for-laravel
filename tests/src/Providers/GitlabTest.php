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
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Facades\Registry;

it('returns name and description of provider', function () {
    expect(gitlab())
        ->name()->toBe('GitLab')
        ->description()->toBe('GitLab Provider');
});

it('checks whether specific feature is supported by provider', function () {
    expect(gitlab())->supports(Feature::ListRepositories)->toBeTrue();
});

it('throws exception when authenticating with an unsupported method', function () {
    Registry::gitlab(
        new Password(
            credentials: new SensitiveParameterValue('zer0day'),
        )
    );
})->throws(
    InvalidCredentialsException::class,
    'Authentication with [Password] is not supported by provider [GitLab].'.
    'Supported authentication methods are [Token].'
);

it('returns currently authenticated user', function () {
    Http::fake(['*/api/v4/user' => snapshot('gitlab/user')]);

    expect(gitlab()->user())
        ->toBeInstanceOf(Owner::class)
        ->id->toBe('14')
        ->name->toBe('johndoe');
});

it('returns a page of repositories from gitlab', function () {
    Http::fake(['*/api/v4/projects*' => snapshot(name: 'gitlab/repository', times: 2)]);

    $repositories = gitlab()->repositories();

    expect($repositories)
        ->toBeInstanceOf(Page::class)
        ->and($repositories->first())
        ->toBeInstanceOf(Repository::class)
        ->path->toBe('diaspora/diaspora-client');
});

it('returns single repository from gitlab', function () {
    Http::fake(['*/api/v4/projects/diaspora%2Fdiaspora-client' => snapshot('gitlab/repository')]);

    expect(gitlab()->repository('diaspora/diaspora-client'))
        ->toBeInstanceOf(Repository::class)
        ->id->toBe('4');
});

it('returns a page of branches for a repository', function () {
    Http::fake(['*/api/v4/projects/diaspora%2Fdiaspora-client/repository/branches*' => snapshot('gitlab/branches')]);

    expect(gitlab()->branches('diaspora/diaspora-client')->items)->toBe(['main', 'dev']);
});

it('returns commits via the query builder', function () {
    Http::fake(['*/api/v4/projects/octocat%2FHello-World/repository/commits*' => snapshot(name: 'gitlab/commit', times: 2)]);

    $commits = gitlab()->commits('octocat/Hello-World')->branch('main')->get();

    expect($commits->first())
        ->toBeInstanceOf(Commit::class)
        ->sha->toBe('ed899a2f4b50b4370feeea94676502b42383c746');
});

it('returns single commit by hash', function () {
    Http::fake(['*/api/v4/projects/octocat%2FHello-World/repository/commits/ed899a2f4b50b4370feeea94676502b42383c746' => snapshot('gitlab/commit')]);

    expect(gitlab()->commit('octocat/Hello-World', 'ed899a2f4b50b4370feeea94676502b42383c746'))
        ->toBeInstanceOf(Commit::class)
        ->sha->toBe('ed899a2f4b50b4370feeea94676502b42383c746');
});

it('returns clone url with token auth', function () {
    $credentials = new Token(
        credentials: new SensitiveParameterValue('myToken'),
    );

    expect(gitlab()->cloneUrlForRepository('testing/ok', 'john', $credentials))
        ->toBe('https://oauth2:myToken@gitlab.com/testing/ok.git');
});
