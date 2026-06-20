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
    expect(gitlab())
        ->name()->toBe('GitLab')
        ->description()->toBe('GitLab Provider');
});

it('checks whether specific feature is supported by provider', function () {
    expect(gitlab())
        ->supports(Feature::ListRepositories)->toBeTrue()
        ->supports('not-existing-feature')->toBeFalse();
});

it('throws exception when trying to authenticate using not supported authentiction method', function () {
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

it('returns gitlab features', function () {
    $features = array_map(fn (Feature $feature) => $feature->id, gitlab()->features());

    expect($features)->toBe([
        Feature::ListRepositories,
        Feature::FindRepository,
        Feature::ListCommits,
        Feature::FindCommits,
        Feature::ListRepositoryBranches,
    ]);
});

it('returns gitlab auth methods', function () {
    expect(gitlab()->authenticationMethods())->toBe([
        Token::class,
    ]);
});

it('returns currently authenticated user', function () {
    Http::fake([
        '/user' => snapshot('gitlab/user'),
    ]);

    $user = gitlab()->user();

    expect($user)
        ->toBeInstanceOf(Owner::class)
        ->id->toBe('14')
        ->name->toBe('johndoe')
        ->avatar->toBe('https://gitlab.com/uploads/-/system/user/avatar/14/avatar.png');
});

it('returns list of repositories from gitlab', function () {
    Http::fake([
        '/api/v4/projects' => snapshot(name: 'gitlab/repository', times: 2),
    ]);

    $repositories = gitlab()->repositories();

    expect($repositories)
        ->toBeInstanceOf(Collection::class)
        ->toHaveLength(2)
        ->and($repositories->first())
        ->toBeInstanceOf(Repository::class)
        ->id->toBe('4')
        ->name->toBe('diaspora-client')
        ->path->toBe('diaspora/diaspora-client')
        ->description->toBe('This your gitlab repo!')
        ->defaultBranch->toBe('main')
        ->owner->toBeInstanceOf(Owner::class)
        ->owner->id->toBe('2')
        ->owner->name->toBe('diaspora')
        ->owner->avatar->toBe('https://gitlab.example.com/avatar.jpg')
        ->createdAt->toBeInstanceOf(Carbon::class)
        ->createdAt->format('d.m.Y H:i')->toBe('30.09.2013 13:46')
        ->lastActivityAt->toBeInstanceOf(Carbon::class)
        ->lastActivityAt->format('d.m.Y H:i')->toBe('30.09.2013 13:46');
});

it('returns single repository by owner and name from gitlab', function () {
    Http::fake([
        '/api/v4/projects/diaspora%2Fdiaspora-client' => snapshot('gitlab/repository'),
    ]);

    $repository = gitlab()->repository('diaspora/diaspora-client');

    expect($repository)
        ->toBeInstanceOf(Repository::class)
        ->id->toBe('4')
        ->name->toBe('diaspora-client')
        ->path->toBe('diaspora/diaspora-client')
        ->description->toBe('This your gitlab repo!')
        ->defaultBranch->toBe('main')
        ->owner->toBeInstanceOf(Owner::class)
        ->owner->id->toBe('2')
        ->owner->name->toBe('diaspora')
        ->owner->avatar->toBe('https://gitlab.example.com/avatar.jpg')
        ->createdAt->toBeInstanceOf(Carbon::class)
        ->createdAt->format('d.m.Y H:i')->toBe('30.09.2013 13:46')
        ->lastActivityAt->toBeInstanceOf(Carbon::class)
        ->lastActivityAt->format('d.m.Y H:i')->toBe('30.09.2013 13:46');
});

it('returns list of branches for specific repository', function () {
    Http::fake([
        '/api/v4/projects/diaspora%2Fdiaspora-client/repository/branches' => snapshot('gitlab/branches'),
    ]);

    $branches = gitlab()->branches('diaspora/diaspora-client');

    expect($branches)->toBe(['main', 'dev']);
});

it('returns list of commits for repository', function () {
    Http::fake([
        '/api/v4/projects/octocat%2FHello-World/repository/commits/main?page=2' => snapshot(name: 'gitlab/commit', times: 2),
    ]);

    $commits = gitlab()->commits('octocat/Hello-World', 'main', 2);

    expect($commits)
        ->toBeInstanceOf(Collection::class)
        ->toHaveLength(2)
        ->and($commits->first())
        ->toBeInstanceOf(Commit::class)
        ->sha->toBe('ed899a2f4b50b4370feeea94676502b42383c746')
        ->message->toBe('Replace sanitize with escape once')
        ->author->toBeInstanceOf(Author::class)
        ->author->name->toBe('Example User')
        ->author->email->toBe('user@example.com')
        ->author->avatar->toBeNull()
        ->url->toBe('https://gitlab.example.com/janedoe/gitlab-foss/-/commit/ed899a2f4b50b4370feeea94676502b42383c746')
        ->commitAt->toBeInstanceOf(Carbon::class)
        ->commitAt->format('d.m.Y H:i')->toBe('20.09.2021 11:50');
});

it('returns single commit from repository by hash', function () {
    Http::fake([
        '/api/v4/projects/octocat%2FHello-World/repository/commits/ed899a2f4b50b4370feeea94676502b42383c746' => snapshot('gitlab/commit'),
    ]);

    $commit = gitlab()->commit('octocat/Hello-World', 'ed899a2f4b50b4370feeea94676502b42383c746');

    expect($commit)
        ->toBeInstanceOf(Commit::class)
        ->sha->toBe('ed899a2f4b50b4370feeea94676502b42383c746')
        ->message->toBe('Replace sanitize with escape once')
        ->author->toBeInstanceOf(Author::class)
        ->author->name->toBe('Example User')
        ->author->email->toBe('user@example.com')
        ->author->avatar->toBeNull()
        ->url->toBe('https://gitlab.example.com/janedoe/gitlab-foss/-/commit/ed899a2f4b50b4370feeea94676502b42383c746')
        ->commitAt->toBeInstanceOf(Carbon::class)
        ->commitAt->format('d.m.Y H:i')->toBe('20.09.2021 11:50');
});

it('returns clone url with token auth', function () {
    $credentials = new Token(
        credentials: new SensitiveParameterValue('myToken'),
    );

    $cloneUrl = gitlab()->cloneUrlForRepository('testing/ok', 'john', $credentials);

    expect($cloneUrl)->toBe('https://oauth2:myToken@gitlab.com/testing/ok.git');
});
