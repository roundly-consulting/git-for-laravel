<?php

declare(strict_types=1);

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Auth\TokenManager;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Credentials\OauthToken;

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
