<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\Credentials\Token;
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

it('throws exception by default that feature is not supported', function (array $setup) {
    $provider = new BaseProvider;

    call_user_func([$provider, $setup['method']], ...$setup['props']);
})
    ->throws(FeatureNotSupportedException::class)
    ->with([
        'repositories' => [fn () => ['method' => 'repositories', 'props' => ['john']]],
        'repository' => [fn () => ['method' => 'repository', 'props' => ['john', 'example']]],
        'commits' => [fn () => ['method' => 'commits', 'props' => ['john', 'main']]],
        'commit' => [fn () => ['method' => 'commit', 'props' => ['john', 'f7591a13eda445d9a9167f98eb870319f4b6c2d8']]],
        'branches' => [fn () => ['method' => 'branches', 'props' => ['testing/ok']]],
    ]);

it('throws exception by default that feature is not supported - cloneUrlForRepository', function () {
    $provider = new BaseProvider;

    $credentials = new Token(
        credentials: new SensitiveParameterValue('test'),
    );

    $provider->cloneUrlForRepository('testing/ok', 'john', $credentials);
})->throws(FeatureNotSupportedException::class);
