<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Facades\Registry;

function appCred(): GithubAppToken
{
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    openssl_pkey_export($resource, $privateKey);

    return GithubAppToken::for(appId: '123', installationId: '999', privateKey: $privateKey);
}

it('uses the minted installation token for reads without re-minting', function () {
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::response([
            'token' => 'ghs_x',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ]),
        '*/user' => Http::response(['id' => 1, 'login' => 'octocat']),
    ]);

    $provider = Registry::github(appCred());

    $provider->user();
    $provider->user();

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), '/user')
        || $request->hasHeader('Authorization', 'Bearer ghs_x'));

    Http::assertSentCount(3);
});

it('uses the minted token on write requests too', function () {
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::response([
            'token' => 'ghs_write',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ]),
        '*/repos/acme/api/hooks' => Http::response(['id' => 1, 'config' => ['url' => 'u'], 'events' => ['push'], 'active' => true]),
    ]);

    Registry::github(appCred())->createWebhook('acme/api', new NewWebhook(url: 'https://app.test/hook'));

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), '/hooks')
        || $request->hasHeader('Authorization', 'Bearer ghs_write'));
});

it('builds an app credential from config via the registry', function () {
    $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($resource, $privateKey);

    config()->set('git.providers.github.app.id', '321');
    config()->set('git.providers.github.app.installation_id', '654');
    config()->set('git.providers.github.app.private_key', $privateKey);

    Http::fake([
        '*/app/installations/654/access_tokens' => Http::response([
            'token' => 'ghs_config',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ]),
        '*/user' => Http::response(['id' => 1, 'login' => 'octocat']),
    ]);

    Registry::github()->user();

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), '/user')
        || $request->hasHeader('Authorization', 'Bearer ghs_config'));
});
