<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use RoundlyConsulting\Git\Dto\Author;
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
    expect(github())
        ->name()->toBe('GitHub')
        ->description()->toBe('GitHub Provider');
});

it('checks whether specific feature is supported by provider', function () {
    expect(github())
        ->supports(Feature::ListRepositories)->toBeTrue()
        ->supports(Feature::CreateRepository)->toBeTrue();
});

it('throws exception when authenticating with an unsupported method', function () {
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

it('returns github auth methods', function () {
    expect(github()->authenticationMethods())->toBe([
        Token::class,
    ]);
});

it('returns currently authenticated user', function () {
    Http::fake([
        '*/user' => snapshot('github/user'),
    ]);

    $user = github()->user();

    expect($user)
        ->toBeInstanceOf(Owner::class)
        ->id->toBe('1')
        ->name->toBe('octocat')
        ->avatar->toBe('https://github.com/images/error/octocat_happy.gif');
});

it('returns a page of repositories from github', function () {
    Http::fake([
        '*/user/repos*' => snapshot(name: 'github/repository', times: 2),
    ]);

    $repositories = github()->repositories();

    expect($repositories)
        ->toBeInstanceOf(Page::class)
        ->and($repositories->count())->toBe(2)
        ->and($repositories->first())
        ->toBeInstanceOf(Repository::class)
        ->id->toBe('1296269')
        ->name->toBe('Hello-World')
        ->path->toBe('octocat/Hello-World');
});

it('auto-paginates all repositories lazily', function () {
    $repo = snapshotData('github/repository');

    Http::fakeSequence('*/user/repos*')
        ->push(array_fill(0, 2, $repo))   // full page → has more
        ->push([$repo]);                  // partial page → stop

    $all = github()->allRepositories(perPage: 2);

    expect($all)->toBeInstanceOf(LazyCollection::class)
        ->and($all->count())->toBe(3);
});

it('returns single repository by owner and name from github', function () {
    Http::fake([
        '*/repos/octocat/Hello-World' => snapshot('github/repository'),
    ]);

    expect(github()->repository('octocat/Hello-World'))
        ->toBeInstanceOf(Repository::class)
        ->id->toBe('1296269')
        ->defaultBranch->toBe('master');
});

it('returns a page of branches for a repository', function () {
    Http::fake([
        '*/repos/octocat/Hello-World/branches*' => snapshot('github/branches'),
    ]);

    expect(github()->branches('octocat/Hello-World')->items)->toBe(['main', 'dev']);
});

it('returns commits via the query builder', function () {
    Http::fake([
        '*/repos/octocat/Hello-World/commits*' => snapshot(name: 'github/commit', times: 2),
    ]);

    $commits = github()->commits('octocat/Hello-World')->branch('main')->get();

    expect($commits)
        ->toBeInstanceOf(Page::class)
        ->and($commits->first())
        ->toBeInstanceOf(Commit::class)
        ->sha->toBe('6dcb09b5b57875f334f61aebed695e2e4193db5e')
        ->message->toBe('Fix all the bugs')
        ->author->toBeInstanceOf(Author::class);
});

it('encodes branch names in the commit query', function () {
    Http::fake([
        '*' => Http::response([snapshotData('github/commit')]),
    ]);

    github()->commits('octocat/Hello-World')->branch('feature/new ui')->get();

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'sha=feature%2Fnew');
    });
});

it('returns single commit by hash', function () {
    Http::fake([
        '*/repos/octocat/Hello-World/commits/6dcb09b5b57875f334f61aebed695e2e4193db5e' => snapshot('github/commit'),
    ]);

    expect(github()->commit('octocat/Hello-World', '6dcb09b5b57875f334f61aebed695e2e4193db5e'))
        ->toBeInstanceOf(Commit::class)
        ->sha->toBe('6dcb09b5b57875f334f61aebed695e2e4193db5e');
});

it('returns clone url with token auth', function () {
    $credentials = new Token(
        credentials: new SensitiveParameterValue('myToken'),
    );

    expect(github()->cloneUrlForRepository('testing/ok', 'john', $credentials))
        ->toBe('https://token:myToken@github.com/testing/ok.git');
});

it('exposes feature info as dtos', function () {
    $info = github()->featureInfo();

    expect($info[0]->id)->toBe(Feature::ListRepositories)
        ->and($info[0]->description)->toBeString();
});
