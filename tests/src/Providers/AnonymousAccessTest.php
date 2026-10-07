<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Input\NewBranch;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Facades\Git;

/**
 * GitHub answers an `Authorization: Bearer` with no token after it as a bad credential
 * (`401 Bad credentials`, recorded live) rather than as an anonymous caller — so the
 * header has to be ABSENT, not empty, for an unauthenticated read of a public repository.
 */
function answerAnonymousOnly(array|string $body): Closure
{
    return fn (Request $request) => $request->hasHeader('Authorization')
        ? Http::response(['message' => 'Bad credentials', 'status' => '401'], 401)
        : Http::response($body);
}

beforeEach(function () {
    // An environment token must not turn these into authenticated calls.
    foreach (['github', 'gitlab', 'bitbucket'] as $provider) {
        config()->set("git.providers.{$provider}.token", null);
    }
});

it('reads a public repository anonymously on every provider', function (string $provider, string $url, string $snapshot) {
    Http::fake([$url => answerAnonymousOnly(snapshotData($snapshot))]);

    $repository = Git::provider($provider)->repository('octocat/hello-world');

    expect($repository->name)->not->toBe('');

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
})->with([
    'github' => ['github', '*/repos/octocat/hello-world', 'github/repository'],
    'gitlab' => ['gitlab', '*/api/v4/projects/octocat%2Fhello-world', 'gitlab/repository'],
    'bitbucket' => ['bitbucket', '*/2.0/repositories/octocat/hello-world', 'bitbucket/repository'],
]);

it('sends no Authorization header on anonymous batch requests', function () {
    Http::fake(['*/repos/*/languages' => answerAnonymousOnly(['PHP' => 100])]);

    $result = Git::github()->batch()->languages(['octocat/a', 'octocat/b']);

    expect($result->successful())->toBeTrue()
        ->and($result->results())->toBe(['octocat/a' => ['PHP' => 100], 'octocat/b' => ['PHP' => 100]]);

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

it('still sends the bearer token when a credential is configured', function () {
    config()->set('git.providers.github.token', 'ghp_configured');

    Http::fake(['*/repos/octocat/hello-world' => snapshot('github/repository')]);

    Git::github()->repository('octocat/hello-world');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer ghp_configured'));
});

it('answers a 401 on an unauthenticated provider as a missing credential', function () {
    Http::fake(['*/user' => Http::response(['message' => 'Requires authentication'], 401)]);

    expect(fn () => Git::github()->user())
        ->toThrow(InvalidCredentialsException::class, 'Provider [GitHub] requires authentication');
});

it('answers a 401 on a read with a credential as a rejected credential', function () {
    Http::fake(['*/user' => Http::response(['message' => 'Bad credentials'], 401)]);

    try {
        github('ghp_revoked')->user();

        $this->fail('Expected an InvalidCredentialsException.');
    } catch (InvalidCredentialsException $exception) {
        expect($exception->getMessage())->toContain('rejected the credential')
            ->not->toContain('ghp_revoked')
            // The forge's own answer stays reachable for a log, as the previous exception.
            ->and($exception->getPrevious())->toBeInstanceOf(RequestException::class)
            ->and($exception->getPrevious()?->response->status())->toBe(401);
    }
});

it('answers a 401 on a write with a credential as a rejected credential', function () {
    Http::fake([
        '*/repos/o/r/commits/main' => Http::response(['sha' => 'basesha']),
        '*/repos/o/r/git/refs' => Http::response(['message' => 'Bad credentials'], 401),
    ]);

    expect(fn () => github('ghp_revoked')->createBranch('o/r', new NewBranch('feature', 'main')))
        ->toThrow(InvalidCredentialsException::class, 'rejected the credential');
});

it('maps a 401 the same way on gitlab and bitbucket', function (string $provider, string $url) {
    Http::fake([$url => Http::response(['message' => '401 Unauthorized'], 401)]);

    expect(fn () => Git::provider($provider)->user())
        ->toThrow(InvalidCredentialsException::class, 'requires authentication');
})->with([
    'gitlab' => ['gitlab', '*/api/v4/user'],
    'bitbucket' => ['bitbucket', '*/2.0/user'],
]);

it('leaves a 404 the documented RequestException, status intact', function () {
    // A repository an anonymous caller (or a token without access) cannot see is a 404 on
    // GitHub — and consumers branch on that status, so it must stay readable.
    Http::fake(['*/repos/acme/private' => Http::response(['message' => 'Not Found'], 404)]);

    try {
        Git::github()->repository('acme/private');

        $this->fail('Expected a RequestException.');
    } catch (RequestException $exception) {
        expect($exception->response->status())->toBe(404);
    }
});

it('leaves a rate-limited 403 the documented, retryable RequestException', function () {
    Http::fake(['*/repos/octocat/hello-world' => Http::response(
        ['message' => 'API rate limit exceeded'],
        403,
        ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Limit' => '60', 'X-RateLimit-Reset' => (string) (time() + 60)],
    )]);

    expect(fn () => Git::github()->repository('octocat/hello-world'))
        ->toThrow(fn (RequestException $exception) => expect($exception->response->status())->toBe(403));
});
