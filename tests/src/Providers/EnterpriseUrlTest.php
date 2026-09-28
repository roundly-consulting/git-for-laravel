<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;

/*
 * The web host (clone URLs, the app install page) is derived from the configured API URL.
 * GitHub Enterprise Server serves its API under `/api/v3` on the web host itself and its
 * app pages under `/github-apps/`; github.com and GHE.com put the API on an `api.` host.
 */

it('derives the enterprise server web host from an /api/v3 url', function (string $api, string $web): void {
    config()->set('git.providers.github.url', $api);
    config()->set('git.providers.github.app.slug', 'my-app');

    expect(github()->repo('acme/app')->cloneUrl('jane', Token::from('ghp_x')))->toBe("https://token:ghp_x@{$web}/acme/app.git")
        ->and(github()->installUrl('s1'))->toBe("https://{$web}/github-apps/my-app/installations/new?state=s1");
})->with([
    'plain' => ['https://ghe.acme.io/api/v3', 'ghe.acme.io'],
    'trailing slash' => ['https://ghe.acme.io/api/v3/', 'ghe.acme.io'],
    'with a port' => ['https://ghe.acme.io:8443/api/v3', 'ghe.acme.io:8443'],
    'api label inside the host' => ['https://git.api.acme.io/api/v3', 'git.api.acme.io'],
]);

it('strips only a leading api. label from an api host', function (string $api, string $web): void {
    config()->set('git.providers.github.url', $api);
    config()->set('git.providers.github.app.slug', 'my-app');

    expect(github()->repo('acme/app')->cloneUrl('jane', Token::from('ghp_x')))->toBe("https://token:ghp_x@{$web}/acme/app.git")
        ->and(github()->installUrl())->toBe("https://{$web}/apps/my-app/installations/new");
})->with([
    'github.com' => ['https://api.github.com', 'github.com'],
    'ghe.com' => ['https://api.acme.ghe.com', 'acme.ghe.com'],
]);

it('falls back to github.com when no api url is configured', function (): void {
    config()->set('git.providers.github.url', null);

    expect(github()->repo('acme/app')->cloneUrl('jane', Token::from('ghp_x')))->toBe('https://token:ghp_x@github.com/acme/app.git');
});

it('derives the bitbucket web host by its leading api label only', function (): void {
    config()->set('git.providers.bitbucket.url', 'https://api.bitbucket.org');

    expect(bitbucket()->repo('ws/app')->cloneUrl('jane', Token::from('secret')))->toBe('https://jane:secret@bitbucket.org/ws/app.git');
});

it('percent-encodes the credential inside a clone url', function (): void {
    config()->set('git.providers.bitbucket.url', 'https://api.bitbucket.org');

    $url = bitbucket()->repo('acme/app')->cloneUrl('me@acme.io', Token::from('p@ss/w:rd'));

    expect($url)->toBe('https://me%40acme.io:p%40ss%2Fw%3Ard@bitbucket.org/acme/app.git')
        ->and(parse_url($url, PHP_URL_HOST))->toBe('bitbucket.org')
        ->and(rawurldecode((string) parse_url($url, PHP_URL_PASS)))->toBe('p@ss/w:rd');
});

it('guards the repository path of a flat clone url', function (): void {
    bitbucket()->cloneUrlForRepository('acme/../victim', 'jane', Token::from('x'));
})->throws(OutOfScopeException::class);
