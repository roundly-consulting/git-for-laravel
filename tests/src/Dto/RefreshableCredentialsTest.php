<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Contracts\RefreshableCredentials;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Credentials\OauthToken;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;

it('redacts every secret on the github app token', function () {
    $cred = GithubAppToken::for('123', '999', 'PEM-SECRET-VALUE');

    $array = $cred->toArray();

    expect($cred)->toBeInstanceOf(RefreshableCredentials::class)
        ->and($array['privateKey'])->toBe('••••')
        ->and($array['appId'])->toBe('123')
        ->and($cred->toJson())->not->toContain('PEM-SECRET-VALUE');
});

it('redacts every secret on the oauth token', function () {
    $cred = OauthToken::for('ACCESS-SECRET', 'REFRESH-SECRET', 'client', 'CLIENT-SECRET', 'https://token.test', Carbon::now());

    $json = $cred->toJson();

    expect($cred->toArray()['accessToken'])->toBe('••••')
        ->and($cred->toArray()['refreshToken'])->toBe('••••')
        ->and($cred->toArray()['clientSecret'])->toBe('••••')
        ->and($json)->not->toContain('ACCESS-SECRET')
        ->and($json)->not->toContain('REFRESH-SECRET')
        ->and($json)->not->toContain('CLIENT-SECRET');
});

/**
 * The `oauth.*` config block was shipped and documented but read by nothing — setting
 * `GITHUB_OAUTH_CLIENT_ID` had no effect anywhere. `forProvider()` is what makes those
 * three keys live; these cases are what keep them that way.
 */
it('builds an oauth token from the provider configured client', function () {
    config()->set('git.providers.github.oauth', [
        'client_id' => 'configured-client',
        'client_secret' => 'CONFIGURED-SECRET',
        'token_url' => 'https://github.test/login/oauth/access_token',
    ]);

    $cred = OauthToken::forProvider(ProviderName::Github, 'ACCESS', 'REFRESH');

    expect($cred->clientId)->toBe('configured-client')
        ->and($cred->clientSecret)->toBe('CONFIGURED-SECRET')
        ->and($cred->tokenUrl)->toBe('https://github.test/login/oauth/access_token')
        ->and($cred->accessTokenValue)->toBe('ACCESS')
        ->and($cred->refreshToken)->toBe('REFRESH')
        ->and($cred->expiresAt)->toBeNull();
});

it('carries the per-user expiry through to the credential', function () {
    config()->set('git.providers.gitlab.oauth', [
        'client_id' => 'gl', 'client_secret' => 'gl-secret', 'token_url' => 'https://gitlab.test/oauth/token',
    ]);

    $expiresAt = Carbon::now()->addHour();

    expect(OauthToken::forProvider(ProviderName::Gitlab, 'A', 'R', $expiresAt)->expiresAt)->toBe($expiresAt);
});

it('refuses to build an oauth token when the provider ships no oauth client', function (string $missing) {
    config()->set('git.providers.github.oauth', [
        'client_id' => 'id',
        'client_secret' => 'secret',
        'token_url' => 'https://github.test/token',
    ]);
    config()->set("git.providers.github.oauth.{$missing}", null);

    expect(fn () => OauthToken::forProvider(ProviderName::Github, 'A', 'R'))
        ->toThrow(InvalidCredentialsException::class, "has no OAuth {$missing} configured");
})->with(['client_id', 'client_secret', 'token_url']);

it('refuses to build an oauth token for a provider with no oauth block at all', function () {
    // Bitbucket ships no `oauth` section — the failure must name the key to set, not
    // hand back a credential built from nulls.
    expect(fn () => OauthToken::forProvider(ProviderName::Bitbucket, 'A', 'R'))
        ->toThrow(InvalidCredentialsException::class, 'git.providers.bitbucket.oauth.client_id');
});
