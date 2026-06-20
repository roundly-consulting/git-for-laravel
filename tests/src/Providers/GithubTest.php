<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Credentials\Password;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\Feature;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Facades\Registry;

it('returns name and description of provider', function () {
    expect(github())
        ->name()->toBe('GitHub')
        ->description()->toBe('GitHub Provider');
});

it('checks whether specific feature is supported by provider', function () {
    expect(github())
        ->supports(Feature::ListRepositories)->toBeTrue()
        ->supports('not-existing-feature')->toBeFalse();
});

it('throws exception when trying to authenticate using not supported authentiction method', function () {
    Registry::github(
        new Password(
            credentials: new SensitiveParameterValue('zer0day'),
        )
    );
})->throws(
    InvalidCredentialsException::class,
    'Authentication with [Password] is not supported by provider [GitHub].'.
    'Supported authentication methods are [Token].'
);

it('returns github features', function () {
    $features = array_map(fn (Feature $feature) => $feature->id, github()->features());

    expect($features)->toBe([
        Feature::ListRepositories,
        Feature::FindRepository,
        Feature::ListCommits,
        Feature::FindCommits,
        Feature::ListRepositoryBranches,
    ]);
});

it('returns github auth methods', function () {
    expect(github()->authenticationMethods())->toBe([
        Token::class,
    ]);
});

it('returns currently authenticated user', function () {
    Http::fake([
        '/user' => snapshot('github/user'),
    ]);

    $user = github()->user();

    expect($user)
        ->toBeInstanceOf(Owner::class)
        ->id->toBe('1')
        ->name->toBe('octocat')
        ->avatar->toBe('https://github.com/images/error/octocat_happy.gif');
});

it('returns list of repositories from github', function () {
    Http::fake([
        '/user/repos' => snapshot(name: 'github/repository', times: 2),
    ]);

    $repositories = github()->repositories();

    expect($repositories)
        ->toBeInstanceOf(Collection::class)
        ->toHaveLength(2)
        ->and($repositories->first())
        ->toBeInstanceOf(Repository::class)
        ->id->toBe('1296269')
        ->name->toBe('Hello-World')
        ->path->toBe('octocat/Hello-World')
        ->description->toBe('This your first repo!')
        ->defaultBranch->toBe('master')
        ->owner->toBeInstanceOf(Owner::class)
        ->owner->id->toBe('1')
        ->owner->name->toBe('octocat')
        ->owner->avatar->toBe('https://github.com/images/error/octocat_happy.gif')
        ->createdAt->toBeInstanceOf(Carbon::class)
        ->createdAt->format('d.m.Y H:i')->toBe('26.01.2011 19:01')
        ->lastActivityAt->toBeInstanceOf(Carbon::class)
        ->lastActivityAt->format('d.m.Y H:i')->toBe('26.01.2011 19:06');
});

it('returns single repository by owner and name from github', function () {
    Http::fake([
        '/repos/octocat/Hello-World' => snapshot('github/repository'),
    ]);

    $repository = github()->repository('octocat/Hello-World');

    expect($repository)
        ->toBeInstanceOf(Repository::class)
        ->id->toBe('1296269')
        ->name->toBe('Hello-World')
        ->path->toBe('octocat/Hello-World')
        ->description->toBe('This your first repo!')
        ->defaultBranch->toBe('master')
        ->owner->toBeInstanceOf(Owner::class)
        ->owner->id->toBe('1')
        ->owner->name->toBe('octocat')
        ->owner->avatar->toBe('https://github.com/images/error/octocat_happy.gif')
        ->createdAt->toBeInstanceOf(Carbon::class)
        ->createdAt->format('d.m.Y H:i')->toBe('26.01.2011 19:01')
        ->lastActivityAt->toBeInstanceOf(Carbon::class)
        ->lastActivityAt->format('d.m.Y H:i')->toBe('26.01.2011 19:06');
});

it('returns list of branches for specific repository', function () {
    Http::fake([
        '/repos/octocat/Hello-World/branches' => snapshot('github/branches'),
    ]);

    $branches = github()->branches('octocat/Hello-World');

    expect($branches)->toBe(['main', 'dev']);
});

it('returns list of commits for repository', function () {
    Http::fake([
        '/repos/octocat/Hello-World/commits?sha=main&page=2' => snapshot(name: 'github/commit', times: 2),
    ]);

    $commits = github()->commits('octocat/Hello-World', 'main', 2);

    expect($commits)
        ->toBeInstanceOf(Collection::class)
        ->toHaveLength(2)
        ->and($commits->first())
        ->toBeInstanceOf(Commit::class)
        ->sha->toBe('6dcb09b5b57875f334f61aebed695e2e4193db5e')
        ->message->toBe('Fix all the bugs')
        ->author->toBeInstanceOf(Author::class)
        ->author->name->toBe('Monalisa Octocat')
        ->author->email->toBe('support@github.com')
        ->author->avatar->toBe('https://github.com/images/error/octocat_happy.gif')
        ->commitAt->toBeInstanceOf(Carbon::class)
        ->commitAt->format('d.m.Y H:i')->toBe('14.04.2011 16:00');
});

it('returns single commit from repository by hash', function () {
    Http::fake([
        '/repos/octocat/Hello-World/commits/6dcb09b5b57875f334f61aebed695e2e4193db5e' => snapshot('github/commit'),
    ]);

    $commit = github()->commit('octocat/Hello-World', '6dcb09b5b57875f334f61aebed695e2e4193db5e');

    expect($commit)
        ->toBeInstanceOf(Commit::class)
        ->sha->toBe('6dcb09b5b57875f334f61aebed695e2e4193db5e')
        ->message->toBe('Fix all the bugs')
        ->author->toBeInstanceOf(Author::class)
        ->author->name->toBe('Monalisa Octocat')
        ->author->email->toBe('support@github.com')
        ->author->avatar->toBe('https://github.com/images/error/octocat_happy.gif')
        ->commitAt->toBeInstanceOf(Carbon::class)
        ->commitAt->format('d.m.Y H:i')->toBe('14.04.2011 16:00');
});

it('returns clone url with token auth', function () {
    $credentials = new Token(
        credentials: new SensitiveParameterValue('myToken'),
    );

    $cloneUrl = github()->cloneUrlForRepository('testing/ok', 'john', $credentials);

    expect($cloneUrl)->toBe('https://token:myToken@github.com/testing/ok.git');
});
