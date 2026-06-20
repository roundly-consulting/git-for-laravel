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
    expect(bitbucket())
        ->name()->toBe('Bitbucket')
        ->description()->toBe('Bitbucket Provider');
});

it('checks whether specific feature is supported by provider', function () {
    expect(bitbucket())
        ->supports(Feature::ListRepositories)->toBeTrue()
        ->supports('not-existing-feature')->toBeFalse();
});

it('throws exception when trying to authenticate using not supported authentiction method', function () {
    Registry::bitbucket(
        new Password(
            credentials: new SensitiveParameterValue('zer0day'),
        )
    );
})->throws(
    InvalidCredentialsException::class,
    'Authentication with [Password] is not supported by provider [Bitbucket].'.
    'Supported authentication methods are [Token].'
);

it('returns bitbucket features', function () {
    $features = array_map(fn (Feature $feature) => $feature->id, bitbucket()->features());

    expect($features)->toBe([
        Feature::ListRepositories,
        Feature::FindRepository,
        Feature::ListCommits,
        Feature::FindCommits,
        Feature::ListRepositoryBranches,
    ]);
});

it('returns bitbucket auth methods', function () {
    expect(bitbucket()->authenticationMethods())->toBe([
        Token::class,
    ]);
});

it('returns currently authenticated user', function () {
    Http::fake([
        '/user' => snapshot('bitbucket/user'),
    ]);

    $user = bitbucket()->user();

    expect($user)
        ->toBeInstanceOf(Owner::class)
        ->id->toBe('3df5334b-adec-4a5c-b8f5-fc784189355f')
        ->name->toBe('john')
        ->avatar->toBe('https://bitbucket.com/avatar.jpg');
});

it('returns list of repositories from bitbucket', function () {
    Http::fake([
        '/2.0/repositories' => Http::response([
            'values' => snapshot(name: 'bitbucket/repository', raw: true, times: 2),
        ]),
    ]);

    $repositories = bitbucket()->repositories();

    expect($repositories)
        ->toBeInstanceOf(Collection::class)
        ->toHaveLength(2)
        ->and($repositories->first())
        ->toBeInstanceOf(Repository::class)
        ->id->toBe('f3c99cd6-7522-4e0d-aaa1-a613c89117fd')
        ->name->toBe('Hello-World')
        ->path->toBe('octocat/Hello-World')
        ->description->toBe('This your first repo!')
        ->defaultBranch->toBe('main')
        ->owner->toBeInstanceOf(Owner::class)
        ->owner->id->toBe('9a550a83-d04b-4242-b264-a223a36503cb')
        ->owner->name->toBe('octocat')
        ->owner->avatar->toBe('https://bitbucket.com/images/error/octocat_happy.gif')
        ->createdAt->toBeInstanceOf(Carbon::class)
        ->createdAt->format('d.m.Y H:i')->toBe('26.01.2011 19:01')
        ->lastActivityAt->toBeInstanceOf(Carbon::class)
        ->lastActivityAt->format('d.m.Y H:i')->toBe('26.01.2011 19:06');
});

it('returns single repository by owner and name from bitbucket', function () {
    Http::fake([
        '/2.0/repositories/octocat/Hello-World' => snapshot('bitbucket/repository'),
    ]);

    $repository = bitbucket()->repository('octocat/Hello-World');

    expect($repository)
        ->toBeInstanceOf(Repository::class)
        ->id->toBe('f3c99cd6-7522-4e0d-aaa1-a613c89117fd')
        ->name->toBe('Hello-World')
        ->path->toBe('octocat/Hello-World')
        ->description->toBe('This your first repo!')
        ->defaultBranch->toBe('main')
        ->owner->toBeInstanceOf(Owner::class)
        ->owner->id->toBe('9a550a83-d04b-4242-b264-a223a36503cb')
        ->owner->name->toBe('octocat')
        ->owner->avatar->toBe('https://bitbucket.com/images/error/octocat_happy.gif')
        ->createdAt->toBeInstanceOf(Carbon::class)
        ->createdAt->format('d.m.Y H:i')->toBe('26.01.2011 19:01')
        ->lastActivityAt->toBeInstanceOf(Carbon::class)
        ->lastActivityAt->format('d.m.Y H:i')->toBe('26.01.2011 19:06');
});

it('returns list of branches for specific repository', function () {
    Http::fake([
        '/2.0/repositories/octocat/Hello-World/refs/branches?limit=1000' => snapshot('bitbucket/branches'),
    ]);

    $branches = bitbucket()->branches('octocat/Hello-World');

    expect($branches)->toBe(['main', 'dev']);
});

it('returns list of commits for repository', function () {
    Http::fake([
        '/2.0/repositories/octocat/Hello-World/commits?include=main&page=2' => Http::response([
            'values' => snapshot(name: 'bitbucket/commit', raw: true, times: 2),
        ]),
    ]);

    $commits = bitbucket()->commits('octocat/Hello-World', 'main', 2);

    expect($commits)
        ->toBeInstanceOf(Collection::class)
        ->toHaveLength(2)
        ->and($commits->first())
        ->toBeInstanceOf(Commit::class)
        ->sha->toBe('f7591a13eda445d9a9167f98eb870319f4b6c2d8')
        ->message->toBe('Add a GEORDI_OUTPUT_DIR setting')
        ->author->toBeInstanceOf(Author::class)
        ->author->name->toBe('Brodie Rao')
        ->author->email->toBe('a@b.c')
        ->author->avatar->toBeNull()
        ->url->toBe('https://bitbucket.org/bitbucket/geordi/commits/f7591a13eda445d9a9167f98eb870319f4b6c2d8')
        ->commitAt->toBeInstanceOf(Carbon::class)
        ->commitAt->format('d.m.Y H:i')->toBe('16.07.2012 19:37');
});

it('returns single commit from repository by hash', function () {
    Http::fake([
        '/2.0/repositories/octocat/Hello-World/commit/f7591a13eda445d9a9167f98eb870319f4b6c2d8' => snapshot('bitbucket/commit'),
    ]);

    $commit = bitbucket()->commit('octocat/Hello-World', 'f7591a13eda445d9a9167f98eb870319f4b6c2d8');

    expect($commit)
        ->toBeInstanceOf(Commit::class)
        ->sha->toBe('f7591a13eda445d9a9167f98eb870319f4b6c2d8')
        ->message->toBe('Add a GEORDI_OUTPUT_DIR setting')
        ->author->toBeInstanceOf(Author::class)
        ->author->name->toBe('Brodie Rao')
        ->author->email->toBe('a@b.c')
        ->author->avatar->toBeNull()
        ->url->toBe('https://bitbucket.org/bitbucket/geordi/commits/f7591a13eda445d9a9167f98eb870319f4b6c2d8')
        ->commitAt->toBeInstanceOf(Carbon::class)
        ->commitAt->format('d.m.Y H:i')->toBe('16.07.2012 19:37');
});

it('returns clone url with token auth', function () {
    $credentials = new Token(
        credentials: new SensitiveParameterValue('myToken'),
    );

    $cloneUrl = bitbucket()->cloneUrlForRepository('testing/ok', 'john', $credentials);

    expect($cloneUrl)->toBe('https://john:myToken@bitbucket.org/testing/ok.git');
});
