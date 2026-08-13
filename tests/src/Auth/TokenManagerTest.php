<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Auth\TokenManager;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Credentials\OauthToken;
use RoundlyConsulting\Git\Dto\Input\InstallationTokenScope;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;

function appCredentials(): GithubAppToken
{
    [$privateKey] = generateRsaKeypair();

    return GithubAppToken::for(
        appId: '123',
        installationId: '999',
        privateKey: $privateKey,
    );
}

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
