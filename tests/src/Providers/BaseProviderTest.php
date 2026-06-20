<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\Input\NewBranch;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Tests\testable\BaseProvider;

it('returns empty array as default for features and auth methods', function () {
    $provider = new BaseProvider;

    expect($provider)
        ->features()->toBe([])
        ->authenticationMethods()->toBe([]);
});

it('returns default user', function () {
    $provider = new BaseProvider;

    expect($provider->user())
        ->toBeInstanceOf(Owner::class)
        ->id->toBe('unknown')
        ->name->toBe('Unknown')
        ->avatar->toBeNull();
});

it('returns null rate limit before any request', function () {
    expect((new BaseProvider)->rateLimit())->toBeNull();
});

it('throws feature not supported by default', function (array $setup) {
    $provider = new BaseProvider;

    call_user_func([$provider, $setup['method']], ...$setup['props']);
})
    ->throws(FeatureNotSupportedException::class)
    ->with([
        'repositories' => [fn () => ['method' => 'repositories', 'props' => []]],
        'allRepositories' => [fn () => ['method' => 'allRepositories', 'props' => []]],
        'repository' => [fn () => ['method' => 'repository', 'props' => ['john/example']]],
        'commit' => [fn () => ['method' => 'commit', 'props' => ['john', 'f7591a13']]],
        'branches' => [fn () => ['method' => 'branches', 'props' => ['testing/ok']]],
        'pullRequests' => [fn () => ['method' => 'pullRequests', 'props' => ['john/ok']]],
        'pullRequest' => [fn () => ['method' => 'pullRequest', 'props' => ['john/ok', 1]]],
        'issues' => [fn () => ['method' => 'issues', 'props' => ['john/ok']]],
        'issue' => [fn () => ['method' => 'issue', 'props' => ['john/ok', 1]]],
        'tags' => [fn () => ['method' => 'tags', 'props' => ['john/ok']]],
        'releases' => [fn () => ['method' => 'releases', 'props' => ['john/ok']]],
        'release' => [fn () => ['method' => 'release', 'props' => ['john/ok', 'v1']]],
        'contents' => [fn () => ['method' => 'contents', 'props' => ['john/ok', 'README.md']]],
        'compare' => [fn () => ['method' => 'compare', 'props' => ['john/ok', 'a', 'b']]],
        'contributors' => [fn () => ['method' => 'contributors', 'props' => ['john/ok']]],
        'languages' => [fn () => ['method' => 'languages', 'props' => ['john/ok']]],
        'searchRepositories' => [fn () => ['method' => 'searchRepositories', 'props' => ['laravel']]],
        'createBranch' => [fn () => ['method' => 'createBranch', 'props' => ['john/ok', new NewBranch('x', 'main')]]],
        'createRepository' => [fn () => ['method' => 'createRepository', 'props' => [new NewRepository('x')]]],
    ]);

it('throws feature not supported for cloneUrlForRepository', function () {
    $provider = new BaseProvider;

    $credentials = new Token(
        credentials: new SensitiveParameterValue('test'),
    );

    $provider->cloneUrlForRepository('testing/ok', 'john', $credentials);
})->throws(FeatureNotSupportedException::class);
