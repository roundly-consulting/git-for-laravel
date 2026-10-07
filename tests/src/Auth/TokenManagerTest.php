<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Auth\TokenManager;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Credentials\OauthToken;
use RoundlyConsulting\Git\Dto\Input\InstallationTokenScope;
use RoundlyConsulting\Git\Events\OauthTokenRefreshed;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;

it('mints an installation token and caches it', function () {
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::response([
            'token' => 'ghs_minted',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ]),
    ]);

    $manager = app(TokenManager::class);
    $cred = appCredentials();

    expect($manager->installationToken($cred))->toBe('ghs_minted')
        ->and($manager->installationToken($cred))->toBe('ghs_minted');

    Http::assertSentCount(1);
});

it('re-mints once the cached installation token expires', function () {
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::sequence()
            ->push(['token' => 'first', 'expires_at' => Carbon::now()->subMinute()->toIso8601String()])
            ->push(['token' => 'second', 'expires_at' => Carbon::now()->addHour()->toIso8601String()]),
    ]);

    $manager = app(TokenManager::class);
    $cred = appCredentials();

    expect($manager->installationToken($cred))->toBe('first')
        ->and($manager->installationToken($cred))->toBe('second');
});

it('returns the stored oauth token while it is still valid', function () {
    Http::preventStrayRequests();

    $cred = OauthToken::for('valid', 'refresh', 'client', 'secret', 'https://token.test', Carbon::now()->addHour());

    expect(app(TokenManager::class)->oauthToken($cred))->toBe('valid');
});

it('refreshes an expired oauth token and reuses the cache', function () {
    Http::fake(['https://token.test' => Http::response([
        'access_token' => 'refreshed',
        'expires_in' => 3600,
    ])]);

    $cred = OauthToken::for('expired', 'refresh', 'client', 'secret', 'https://token.test', Carbon::now()->subMinute());

    $manager = app(TokenManager::class);

    expect($manager->oauthToken($cred))->toBe('refreshed')
        ->and($manager->oauthToken($cred))->toBe('refreshed');

    Http::assertSentCount(1);
});

it('persists a rotated refresh token', function () {
    Http::fake(['https://token.test' => Http::response([
        'access_token' => 'rotated-access',
        'refresh_token' => 'rotated-refresh',
        'expires_in' => 3600,
    ])]);

    $cred = OauthToken::for('expired', 'old-refresh', 'client', 'secret', 'https://token.test', Carbon::now()->subMinute());

    $manager = app(TokenManager::class);
    $manager->oauthToken($cred);

    $rotated = OauthToken::for('expired', 'rotated-refresh', 'client', 'secret', 'https://token.test', Carbon::now()->subMinute());

    expect($manager->oauthToken($rotated))->toBe('rotated-access');

    Http::assertSentCount(1);
});

it('surfaces an upstream mint error', function () {
    Http::fake(['*/app/installations/999/access_tokens' => Http::response([], 500)]);

    expect(fn () => app(TokenManager::class)->installationToken(appCredentials()))
        ->toThrow(RequestException::class);
});

it('mints a repository-scoped token, sending exactly the requested scope', function () {
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::response([
            'token' => 'ghs_scoped',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ]),
    ]);

    $cred = appCredentials()->forScope(new InstallationTokenScope(
        repositoryIds: ['40823311'],
        permissions: ['contents' => 'write', 'pull_requests' => 'write'],
    ));

    expect(app(TokenManager::class)->installationToken($cred))->toBe('ghs_scoped');

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'repository_ids' => [40823311],
        'permissions' => ['contents' => 'write', 'pull_requests' => 'write'],
    ]);
});

it('sends a repository NAME selector without its owner prefix', function () {
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::response([
            'token' => 'ghs_named',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ]),
    ]);

    $cred = appCredentials()->forScope(new InstallationTokenScope(repositories: ['acme-inc/platform-api']));

    app(TokenManager::class)->installationToken($cred);

    // GitHub 422s on "owner/name" here — the installation already names the account.
    Http::assertSent(fn (Request $request): bool => $request->data() === ['repositories' => ['platform-api']]);
});

it('keys the cache per scope so a scoped mint never receives a wider token', function () {
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::sequence()
            ->push(['token' => 'wide', 'expires_at' => Carbon::now()->addHour()->toIso8601String()])
            ->push(['token' => 'narrow', 'expires_at' => Carbon::now()->addHour()->toIso8601String()])
            ->push(['token' => 'other', 'expires_at' => Carbon::now()->addHour()->toIso8601String()]),
    ]);

    $manager = app(TokenManager::class);

    expect($manager->installationToken(appCredentials()))->toBe('wide')
        ->and($manager->installationToken(appCredentials()->forScope(new InstallationTokenScope(repositoryIds: ['1']))))->toBe('narrow')
        ->and($manager->installationToken(appCredentials()->forScope(new InstallationTokenScope(repositoryIds: ['2']))))->toBe('other');

    Http::assertSentCount(3);
});

it('keys the cache per HOST so two forges never share an installation token', function () {
    // App and installation ids are numeric and PER HOST, so app 123 / installation 999 on
    // github.com and the same pair on a GitHub Enterprise instance are unrelated
    // credentials. The cache is read before any HTTP call, so a key that omits the host
    // hands the enterprise caller the github.com token — and vice versa.
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::sequence()
            ->push(['token' => 'ghs_dotcom', 'expires_at' => Carbon::now()->addHour()->toIso8601String()])
            ->push(['token' => 'ghs_enterprise', 'expires_at' => Carbon::now()->addHour()->toIso8601String()]),
    ]);

    [$privateKey] = generateRsaKeypair();

    $manager = app(TokenManager::class);

    $dotcom = GithubAppToken::for('123', '999', $privateKey);
    $enterprise = GithubAppToken::for('123', '999', $privateKey, 'https://github.acme-inc.test/api/v3');

    expect($manager->installationToken($dotcom))->toBe('ghs_dotcom')
        ->and($manager->installationToken($enterprise))->toBe('ghs_enterprise')
        // Each host still caches on its own key.
        ->and($manager->installationToken($dotcom))->toBe('ghs_dotcom');

    Http::assertSentCount(2);
});

it('hits one cache entry for the same scope written in a different order', function () {
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::response([
            'token' => 'ghs_once',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ]),
    ]);

    $manager = app(TokenManager::class);

    $manager->installationToken(appCredentials()->forScope(new InstallationTokenScope(
        repositoryIds: ['1', '2'],
        permissions: ['contents' => 'write', 'metadata' => 'read'],
    )));

    $manager->installationToken(appCredentials()->forScope(new InstallationTokenScope(
        repositoryIds: ['2', '1'],
        permissions: ['metadata' => 'read', 'contents' => 'write'],
    )));

    Http::assertSentCount(1);
});

it('reports a vanished installation as a credential failure', function () {
    Http::fake(['*/app/installations/999/access_tokens' => Http::response([], 404)]);

    expect(fn () => app(TokenManager::class)->installationToken(appCredentials()))
        ->toThrow(InvalidCredentialsException::class);
});

it('reports a refused scope as a credential failure', function () {
    Http::fake(['*/app/installations/999/access_tokens' => Http::response([], 422)]);

    $cred = appCredentials()->forScope(new InstallationTokenScope(repositoryIds: ['404']));

    expect(fn () => app(TokenManager::class)->installationToken($cred))
        ->toThrow(InvalidCredentialsException::class);
});

it('refuses a scope that names no repository instead of minting installation-wide', function () {
    Http::preventStrayRequests();

    // The failure this blocks: a consumer whose repository ids came back empty would
    // otherwise receive a token for EVERY repository in the installation, for an hour,
    // with no error and a cache key that looks correctly scoped.
    $cred = appCredentials()->forScope(new InstallationTokenScope(permissions: ['contents' => 'write']));

    expect(fn () => app(TokenManager::class)->installationToken($cred))
        ->toThrow(InvalidCredentialsException::class);
});

it('still mints wide for a credential with NO scope at all', function () {
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::response([
            'token' => 'ghs_wide',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ]),
    ]);

    // `scope === null` is the deliberate connection-wide read path (listing an
    // installation's repositories), and must keep working.
    expect(app(TokenManager::class)->installationToken(appCredentials()))->toBe('ghs_wide');

    Http::assertSent(fn (Request $request): bool => $request->data() === []);
});

it('leaves a 403 retryable rather than marking the connection broken', function () {
    // GitHub answers 403 for a rate limit as well as a suspended installation. Mapping it
    // to InvalidCredentialsException would let one throttled minute permanently break a
    // working connection, because consumers read that exception as "reconnect required".
    Http::fake(['*/app/installations/999/access_tokens' => Http::response([], 403)]);

    expect(fn () => app(TokenManager::class)->installationToken(appCredentials()))
        ->toThrow(RequestException::class);
});

it('rejects an installation id that could forge another scope cache key', function () {
    // "999:<digest>" would concatenate into a key byte-identical to the SCOPED key for
    // installation 999 — and the cache is read before any HTTP call.
    expect(fn () => GithubAppToken::for('123', '999:'.str_repeat('a', 64), 'key'))
        ->toThrow(InvalidCredentialsException::class);
});

it('rejects a non-numeric repository id rather than sending 0 to github', function () {
    expect(fn () => new InstallationTokenScope(repositoryIds: ['acme/api']))
        ->toThrow(InvalidCredentialsException::class);
});

it('mints a metadata-only token for the one operation that cannot name a repository', function () {
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::response([
            'token' => 'ghs_metadata',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ]),
    ]);

    // Listing an installation's repositories is the only legitimate wide mint. It is
    // wide on the REPOSITORY axis and as narrow as possible on the other: without this,
    // a repository listing hands out `contents: write` across the whole account.
    $cred = appCredentials()->forScope(InstallationTokenScope::metadataOnly());

    expect(app(TokenManager::class)->installationToken($cred))->toBe('ghs_metadata');

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'permissions' => ['metadata' => 'read'],
    ]);
});

it('keeps the metadata-only token in its own cache entry', function () {
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::sequence()
            ->push(['token' => 'metadata', 'expires_at' => Carbon::now()->addHour()->toIso8601String()])
            ->push(['token' => 'wide', 'expires_at' => Carbon::now()->addHour()->toIso8601String()]),
    ]);

    $manager = app(TokenManager::class);

    expect($manager->installationToken(appCredentials()->forScope(InstallationTokenScope::metadataOnly())))->toBe('metadata')
        // A null scope is still "everything the installation granted" and must not be
        // served the read-only one, nor the other way round.
        ->and($manager->installationToken(appCredentials()))->toBe('wide');
});

it('tells the host application when the refresh token rotated', function () {
    // The cache is NOT a durable home for a rotated refresh token: its entry expires with
    // the ACCESS token, and after that the only copy left is the one the host persisted —
    // which the provider invalidated at rotation. Without this event the connection breaks
    // an hour later with nothing having reported the change.
    Event::fake();

    Http::fake(['https://token.test' => Http::response([
        'access_token' => 'rotated-access',
        'refresh_token' => 'rotated-refresh',
        'expires_in' => 3600,
    ])]);

    $cred = OauthToken::for('expired', 'old-refresh', 'client', 'secret', 'https://token.test', Carbon::now()->subMinute());

    app(TokenManager::class)->oauthToken($cred);

    Event::assertDispatched(OauthTokenRefreshed::class, function (OauthTokenRefreshed $event): bool {
        return $event->rotated()
            && $event->refreshToken === 'rotated-refresh'
            && $event->accessToken === 'rotated-access'
            && $event->expiresAt->isFuture();
    });
});

it('reports a refresh that did NOT rotate as unrotated', function () {
    Event::fake();

    Http::fake(['https://token.test' => Http::response(['access_token' => 'refreshed', 'expires_in' => 3600])]);

    $cred = OauthToken::for('expired', 'keep-me', 'client', 'secret', 'https://token.test', Carbon::now()->subMinute());

    app(TokenManager::class)->oauthToken($cred);

    Event::assertDispatched(OauthTokenRefreshed::class, fn (OauthTokenRefreshed $event): bool => ! $event->rotated()
        && $event->refreshToken === 'keep-me');
});

it('presents the STORED refresh token, not the one the caller is still holding', function () {
    // After a rotation the caller's own credential carries a refresh token the provider
    // already invalidated — sending it is a hard failure that reads like a revoked grant.
    // This is the window where the package can still fix that itself: the first response
    // rotates AND arrives already expired, so the entry outlives the token inside it and
    // the next call re-refreshes with the rotated token sitting in that entry.
    Http::fake(['https://token.test' => Http::sequence()
        ->push(['access_token' => 'first', 'refresh_token' => 'rotated-refresh', 'expires_in' => 0])
        ->push(['access_token' => 'second', 'expires_in' => 3600]),
    ]);

    $cred = OauthToken::for('expired', 'old-refresh', 'client', 'secret', 'https://token.test', Carbon::now()->subMinute());

    $manager = app(TokenManager::class);

    expect($manager->oauthToken($cred))->toBe('first')
        // The caller's credential still names `old-refresh`; the request must not.
        ->and($manager->oauthToken($cred))->toBe('second');

    $sent = collect(Http::recorded())->map(fn (array $pair): mixed => $pair[0]->data()['refresh_token'] ?? null);

    expect($sent->all())->toBe(['old-refresh', 'rotated-refresh']);
});

it('reports a rejected app key as a credential failure, not a raw http error', function () {
    Http::fake(['*/app/installations/999/access_tokens' => Http::response(['message' => 'A JSON web token could not be decoded'], 401)]);

    try {
        app(TokenManager::class)->installationToken(appCredentials());
        $this->fail('Expected the mint to fail.');
    } catch (InvalidCredentialsException $exception) {
        expect($exception->getMessage())->toContain('401')
            ->and($exception->getPrevious())->toBeInstanceOf(RequestException::class);
    }
});

it('reports a rejected oauth client as a credential failure', function () {
    Http::fake(['https://token.test' => Http::response(['error' => 'invalid_client'], 401)]);

    $cred = OauthToken::for('expired', 'refresh', 'client', 'wrong', 'https://token.test', Carbon::now()->subMinute());

    app(TokenManager::class)->oauthToken($cred);
})->throws(InvalidCredentialsException::class, '401');

it('reports a revoked or expired refresh token as a credential failure', function () {
    // RFC 6749 §5.2: a refresh token that is expired, revoked or already used is answered
    // `400 invalid_grant` — GitLab (Doorkeeper) sends exactly this body.
    Http::fake(['https://token.test' => Http::response([
        'error' => 'invalid_grant',
        'error_description' => 'The provided authorization grant is invalid, expired, revoked, does not match the redirection URI used in the authorization request, or was issued to another client.',
    ], 400)]);

    $cred = OauthToken::for('expired', 'revoked-refresh', 'client', 'secret', 'https://token.test', Carbon::now()->subMinute());

    try {
        app(TokenManager::class)->oauthToken($cred);
        $this->fail('Expected the refresh to fail.');
    } catch (InvalidCredentialsException $exception) {
        expect($exception->getMessage())->toContain('invalid_grant')
            ->and($exception->getPrevious())->toBeInstanceOf(RequestException::class);
    }
});

it('leaves any other refresh failure a retryable http error', function (int $status, array $body) {
    Http::fake(['https://token.test' => Http::response($body, $status)]);

    $cred = OauthToken::for('expired', 'refresh', 'client', 'secret', 'https://token.test', Carbon::now()->subMinute());

    expect(fn () => app(TokenManager::class)->oauthToken($cred))->toThrow(RequestException::class);
})->with([
    'another 400' => [400, ['error' => 'invalid_request']],
    'a 400 with no body' => [400, []],
    'a server error' => [503, ['error' => 'temporarily_unavailable']],
]);
