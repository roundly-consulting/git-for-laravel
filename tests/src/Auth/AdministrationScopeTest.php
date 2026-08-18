<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Auth\TokenManager;
use RoundlyConsulting\Git\Dto\Input\InstallationTokenScope;

it('is not refused as an unscoped mint', function () {
    // The whole point of the factory: creating a repository cannot name one, so the scope
    // is wide on the repository axis — and `TokenManager` refuses a scope that is wide by
    // ACCIDENT. `installationWide` is what separates the two.
    $scope = InstallationTokenScope::administrationOnly();

    expect($scope->isEmpty())->toBeFalse()
        ->and($scope->installationWide)->toBeTrue();

    Http::fake(['*/app/installations/999/access_tokens' => Http::response([
        'token' => 'ghs_admin',
        'expires_at' => Carbon::now()->addHour()->toIso8601String(),
    ])]);

    $cred = appCredentials()->forScope($scope);

    expect(app(TokenManager::class)->installationToken($cred))->toBe('ghs_admin');
});

it('requests exactly one permission', function () {
    Http::fake(['*/app/installations/999/access_tokens' => Http::response([
        'token' => 'ghs_admin',
        'expires_at' => Carbon::now()->addHour()->toIso8601String(),
    ])]);

    app(TokenManager::class)->installationToken(
        appCredentials()->forScope(InstallationTokenScope::administrationOnly())
    );

    // Not the app's configured permission set — a token that could create a repository
    // anywhere must not also be able to write contents everywhere.
    Http::assertSent(fn (Request $r): bool => $r['permissions'] === ['administration' => 'write']
        && ! array_key_exists('repositories', (array) $r->data())
        && ! array_key_exists('repository_ids', (array) $r->data()));
});

it('digests differently from a metadata-only scope', function () {
    // Both are installation-wide, so only the permissions tell them apart. A shared digest
    // would serve an `administration: write` token from the cache entry a metadata read
    // filled — or the reverse, which fails the creation with a permissions error nobody
    // can trace.
    expect(InstallationTokenScope::administrationOnly()->digest())
        ->not->toBe(InstallationTokenScope::metadataOnly()->digest());
});

it('caches separately from a metadata-only mint', function () {
    Http::fake(['*/app/installations/999/access_tokens' => Http::sequence()
        ->push(['token' => 'ghs_metadata', 'expires_at' => Carbon::now()->addHour()->toIso8601String()])
        ->push(['token' => 'ghs_admin', 'expires_at' => Carbon::now()->addHour()->toIso8601String()]),
    ]);

    $manager = app(TokenManager::class);
    $cred = appCredentials();

    expect($manager->installationToken($cred->forScope(InstallationTokenScope::metadataOnly())))->toBe('ghs_metadata')
        ->and($manager->installationToken($cred->forScope(InstallationTokenScope::administrationOnly())))->toBe('ghs_admin');
});
