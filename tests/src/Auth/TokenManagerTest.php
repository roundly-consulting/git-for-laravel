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
