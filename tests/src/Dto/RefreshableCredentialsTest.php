<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Contracts\RefreshableCredentials;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Credentials\OauthToken;

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
