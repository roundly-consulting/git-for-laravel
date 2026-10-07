<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Exceptions\BatchRequestException;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\HttpClientRateLimits\Facades\RateLimits;

function repoBody(string $id, string $fullName): array
{
    return [
        'id' => $id,
        'full_name' => $fullName,
        'name' => str($fullName)->afterLast('/')->toString(),
        'default_branch' => 'main',
        'owner' => ['id' => 1, 'login' => 'acme'],
        'created_at' => '2020-01-01T00:00:00Z',
        'pushed_at' => '2020-02-01T00:00:00Z',
    ];
}

it('fetches repositories concurrently keyed by path', function () {
    Http::fake([
        '*/repos/acme/api' => Http::response(repoBody('1', 'acme/api')),
        '*/repos/acme/web' => Http::response(repoBody('2', 'acme/web')),
    ]);

    $result = github()->batch()->repositories(['acme/api', 'acme/web']);

    expect($result->successful())->toBeTrue()
        ->and($result->results())->toHaveKeys(['acme/api', 'acme/web'])
        ->and($result->get('acme/api'))->toBeInstanceOf(Repository::class)
        ->and($result->count())->toBe(2);

    Http::assertSentCount(2);
});

it('records partial failures and throws on demand', function () {
    Http::fake([
        '*/repos/acme/api' => Http::response(repoBody('1', 'acme/api')),
        '*/repos/acme/web' => Http::response(['message' => 'nope'], 404),
    ]);

    $result = github()->batch()->repositories(['acme/api', 'acme/web']);

    expect($result->failed())->toBeTrue()
        ->and($result->results())->toHaveKey('acme/api')
        ->and($result->errors())->toHaveKey('acme/web')
        ->and($result->errors()['acme/web']->status)->toBe(404);

    expect(fn () => $result->throwOnError())->toThrow(BatchRequestException::class);
});

it('carries provider authentication on pooled requests', function () {
    Http::fake(['*/repos/acme/api' => Http::response(repoBody('1', 'acme/api'))]);

    github()->batch()->repositories(['acme/api']);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer token-value'));
});

it('chunks input beyond the configured concurrency into multiple pools', function () {
    config()->set('git.batch.concurrency', 1);

    Http::fake([
        '*/repos/acme/api' => Http::response(repoBody('1', 'acme/api')),
        '*/repos/acme/web' => Http::response(repoBody('2', 'acme/web')),
    ]);

    $result = github()->batch()->repositories(['acme/api', 'acme/web']);

    expect($result->count())->toBe(2);
    Http::assertSentCount(2);
});

it('captures rate-limit headers after a batch', function () {
    Http::fake(['*/repos/acme/api' => Http::response(repoBody('1', 'acme/api'), 200, [
        'X-RateLimit-Limit' => '5000',
        'X-RateLimit-Remaining' => '4999',
    ])]);

    $provider = github();
    $provider->batch()->repositories(['acme/api']);

    expect($provider->rateLimit())->not->toBeNull()
        ->and($provider->rateLimit()->remaining)->toBe(4999);
});

it('returns an empty result for empty input', function () {
    Http::preventStrayRequests();

    $result = github()->batch()->repositories([]);

    expect($result->count())->toBe(0)
        ->and($result->successful())->toBeTrue();
});

it('throws for a batch on an unsupported resource url', function () {
    Http::preventStrayRequests();

    expect(fn () => bitbucket()->batch()->languages(['a/b']))
        ->toThrow(FeatureNotSupportedException::class);
});

it('records connection failures as batch errors', function () {
    Http::fake(['*/repos/acme/api' => fn () => throw new ConnectionException('boom')]);

    $result = github()->batch()->repositories(['acme/api']);

    expect($result->errors())->toHaveKey('acme/api')
        ->and($result->errors()['acme/api']->status)->toBeNull();
});

it('fans out file contents and pull requests', function () {
    Http::fake([
        '*/repos/acme/api/contents/README.md*' => Http::response([
            'path' => 'README.md', 'content' => base64_encode('hi'), 'sha' => 'x', 'size' => 2,
        ]),
        '*/repos/acme/api/pulls/7' => Http::response([
            'id' => 1, 'number' => 7, 'title' => 't', 'state' => 'open',
            'head' => ['ref' => 'f'], 'base' => ['ref' => 'm'], 'created_at' => '2020-01-01T00:00:00Z',
        ]),
    ]);

    $files = github()->batch()->contents('acme/api', ['README.md']);
    $prs = github()->batch()->pullRequest(['main' => 'acme/api#7']);

    expect($files->get('README.md')->content)->toBe('hi')
        ->and($prs->get('main')->number)->toBe(7);
});

it('reads a batched file over 1 MB through its blob', function () {
    $sha = '9a2b0f2d3c4e5f60718293a4b5c6d7e8f9012345';

    Http::fake([
        '*/repos/acme/api/contents/dist/app.js*' => Http::response([
            'type' => 'file', 'encoding' => 'none', 'size' => 1_200_000, 'path' => 'dist/app.js', 'content' => '', 'sha' => $sha,
        ]),
        "*/repos/acme/api/git/blobs/{$sha}" => Http::response(['sha' => $sha, 'content' => base64_encode('bundle'), 'encoding' => 'base64']),
    ]);

    expect(github()->batch()->contents('acme/api', ['dist/app.js'])->get('dist/app.js')->content)->toBe('bundle');
});

it('reports rate-limited keys without throwing the whole batch', function () {
    config()->set('git.providers.github.rateLimits', ['owner' => 'app', 'maxAttempts' => 1, 'timespan' => 'hour']);

    Http::fake([
        '*/repos/acme/api' => Http::response(repoBody('1', 'acme/api')),
        '*/repos/acme/web' => Http::response(repoBody('2', 'acme/web')),
    ]);

    $result = github()->batch()->repositories(['acme/api', 'acme/web']);

    expect($result->results())->toHaveKey('acme/api')
        ->and($result->errors())->toHaveKey('acme/web')
        ->and($result->errors()['acme/web']->message)->toBe('rate limited');
});

it('accounts pooled requests through the rate limiter', function () {
    config()->set('git.providers.github.rateLimits', ['owner' => 'app', 'maxAttempts' => 1, 'timespan' => 'hour']);

    $limiter = RateLimits::fake();

    Http::fake([
        '*/repos/acme/api' => Http::response(repoBody('1', 'acme/api'), 200, ['X-RateLimit-Remaining' => '4999']),
        '*/repos/acme/web' => Http::response(repoBody('2', 'acme/web')),
    ]);

    $provider = github();
    $result = $provider->batch()->repositories(['acme/api', 'acme/web']);

    expect($result->errors()['acme/web']->message)->toBe('rate limited')
        ->and($provider->rateLimit())->not->toBeNull();

    $limiter->assertAllowed(rateLimitKey('github'));
});
