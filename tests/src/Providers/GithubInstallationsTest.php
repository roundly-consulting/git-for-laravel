<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Crypto\Signature\Key\RsaKey;
use RoundlyConsulting\Git\Dto\Credentials\GithubApp;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\Input\InstallationTokenScope;
use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\Interfaces\Provider;

function appPrivateKey(): string
{
    return RsaKey::generate()->privatePem();
}

function installationPayload(array $overrides = []): array
{
    return array_merge([
        'id' => 51234567,
        'account' => ['login' => 'acme-inc', 'type' => 'Organization', 'id' => 1],
        'repository_selection' => 'selected',
        'permissions' => ['contents' => 'write', 'pull_requests' => 'write', 'metadata' => 'read'],
        'suspended_at' => null,
    ], $overrides);
}

function mintFake(): array
{
    return ['*/app/installations/999/access_tokens' => Http::response([
        'token' => 'ghs_read',
        'expires_at' => Carbon::now()->addHour()->toIso8601String(),
    ])];
}

function githubAsApp(): Provider
{
    return Git::githubApp(GithubApp::for(appId: '123', privateKey: appPrivateKey()));
}

it('looks an installation up as the app', function () {
    Http::fake(['*/app/installations/51234567' => Http::response(installationPayload())]);

    $installation = githubAsApp()->installation('51234567');

    expect($installation)->toBeInstanceOf(Installation::class)
        ->and($installation->id)->toBe('51234567')
        ->and($installation->accountLogin)->toBe('acme-inc')
        ->and($installation->accountType)->toBe('Organization')
        ->and($installation->repositorySelection)->toBe('selected')
        ->and($installation->reachesEveryRepository())->toBeFalse()
        ->and($installation->isSuspended())->toBeFalse()
        ->and($installation->permissions)->toBe(['contents' => 'write', 'pull_requests' => 'write', 'metadata' => 'read']);
});

it('authenticates an installation lookup with the app jwt, not an installation token', function () {
    Http::fake(['*/app/installations/51234567' => Http::response(installationPayload())]);

    githubAsApp()->installation('51234567');

    Http::assertSent(function (Request $request): bool {
        $authorization = $request->header('Authorization')[0] ?? '';
        [$header, $payload] = array_map(
            fn (string $segment): array => (array) json_decode(base64_decode(strtr($segment, '-_', '+/')), true),
            array_slice(explode('.', str_replace('Bearer ', '', $authorization)), 0, 2),
        );

        return $header['alg'] === 'RS256' && $payload['iss'] === '123';
    });

    // No token was minted: an app JWT is a local signature.
    Http::assertSentCount(1);
});

it('reads a suspended, all-repositories installation', function () {
    Http::fake(['*/app/installations/9' => Http::response(installationPayload([
        'id' => 9,
        'repository_selection' => 'all',
        'suspended_at' => '2026-08-01T10:00:00Z',
    ]))]);

    $installation = githubAsApp()->installation('9');

    expect($installation->reachesEveryRepository())->toBeTrue()
        ->and($installation->isSuspended())->toBeTrue()
        ->and($installation->suspendedAt?->toIso8601String())->toContain('2026-08-01');
});

it('reads an installation payload that omits repository_selection as the narrow one', function () {
    Http::fake(['*/app/installations/9' => Http::response(['id' => 9, 'account' => ['login' => 'a', 'type' => 'User']])]);

    expect(githubAsApp()->installation('9')->repositorySelection)->toBe('selected');
});

it('finds an installation by organization and by user', function () {
    Http::fake([
        '*/orgs/acme-inc/installation' => Http::response(installationPayload()),
        '*/users/octocat/installation' => Http::response(installationPayload(['account' => ['login' => 'octocat', 'type' => 'User']])),
    ]);

    expect(githubAsApp()->organizationInstallation('acme-inc')->accountLogin)->toBe('acme-inc')
        ->and(githubAsApp()->userInstallation('octocat')->accountType)->toBe('User');
});

it('lists every installation of the app', function () {
    Http::fake(['*/app/installations*' => Http::response([installationPayload(), installationPayload(['id' => 2])])]);

    expect(githubAsApp()->installations()->all()->items)->toHaveCount(2)
        ->and(githubAsApp()->listInstallations()->items)->toHaveCount(2);
});

it('parses the ENVELOPED installation repositories response', function () {
    // The bug this pins: `/installation/repositories` returns an object, not a list,
    // so reading the root would produce an empty page — which reads as "the
    // installation has no repositories" rather than as a parsing mistake.
    Http::fake([...mintFake(), '*/installation/repositories*' => Http::response([
        'total_count' => 1,
        'repositories' => [[
            'id' => 40823311,
            'full_name' => 'acme-inc/platform-api',
            'name' => 'platform-api',
            'description' => null,
            'default_branch' => 'main',
            'owner' => ['id' => 1, 'login' => 'acme-inc'],
            'created_at' => '2020-01-01T00:00:00Z',
            'pushed_at' => '2026-08-01T00:00:00Z',
        ]],
    ])]);

    $page = Git::github(GithubAppToken::for('123', '999', appPrivateKey()))
        ->installationRepositories();

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0])->toBeInstanceOf(Repository::class)
        ->and($page->items[0]->id)->toBe('40823311')
        ->and($page->items[0]->path)->toBe('acme-inc/platform-api');
});

it('pages lazily through installation repositories', function () {
    Http::fake([...mintFake(), '*/installation/repositories*' => Http::response(['total_count' => 0, 'repositories' => []])]);

    expect(
        Git::github(GithubAppToken::for('123', '999', appPrivateKey()))
            ->allInstallationRepositories()
            ->all()
    )->toBe([]);
});

it('refuses installation calls on providers that have no app installations', function () {
    // Pinned to the CONCRETE exception, not `Exception`: the whole point of putting these
    // on the shared interface is that a provider without app installations answers a
    // typed, catchable refusal. A PHP `Error` from an undefined method is not an
    // `Exception` at all, and a bare `Exception::class` here would still have let a
    // RuntimeException or an InvalidArgumentException through unnoticed.
    expect(fn () => gitlab()->installation('1'))->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => gitlab()->listInstallations())->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => gitlab()->installations()->all())->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => gitlab()->organizationInstallation('acme-inc'))->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => gitlab()->userInstallation('octocat'))->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => gitlab()->installUrl())->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => bitbucket()->installationRepositories())->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => bitbucket()->allInstallationRepositories()->all())->toThrow(FeatureNotSupportedException::class);
});

it('builds an app clone url with a freshly minted token and the x-access-token user', function () {
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::response([
            'token' => 'ghs_clone',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ]),
    ]);

    $credentials = GithubAppToken::for('123', '999', appPrivateKey())
        ->forScope(new InstallationTokenScope(repositoryIds: ['40823311']));

    expect(Git::github($credentials)->cloneUrlForRepository('acme-inc/platform-api', 'acme-inc', $credentials))
        ->toBe('https://x-access-token:ghs_clone@github.com/acme-inc/platform-api.git');
});

it('leaves the PAT clone url byte-identical', function () {
    $credentials = Token::from('myToken');

    expect(github()->cloneUrlForRepository('testing/ok', 'john', $credentials))
        ->toBe('https://token:myToken@github.com/testing/ok.git');
});

it('builds the install url with the configured slug and echoes the state', function () {
    config()->set('git.providers.github.app.slug', 'cosmos-agentic');

    expect(github()->installUrl())->toBe('https://github.com/apps/cosmos-agentic/installations/new')
        ->and(github()->installUrl('abc123'))->toBe('https://github.com/apps/cosmos-agentic/installations/new?state=abc123');
});

it('follows an enterprise host for the install url', function () {
    config()->set('git.providers.github.url', 'https://api.github.example.com');
    config()->set('git.providers.github.app.slug', 'cosmos-agentic');

    expect(github()->installUrl())->toBe('https://github.example.com/apps/cosmos-agentic/installations/new');
});

it('refuses to build an install url with no app slug configured', function () {
    config()->set('git.providers.github.app.slug', null);

    expect(fn () => github()->installUrl())->toThrow(InvalidCredentialsException::class);
});

it('defaults a scope to the configured app permissions', function () {
    $scope = InstallationTokenScope::forRepositories(repositoryIds: ['1']);

    expect($scope->permissions)->toBe([
        'contents' => 'write',
        'pull_requests' => 'write',
        'metadata' => 'read',
    ])->and($scope->isEmpty())->toBeFalse();
});

it('knows a scope with no repository selector is empty', function () {
    expect(InstallationTokenScope::forRepositories()->isEmpty())->toBeTrue();
});

it('REFUSES to put the app jwt into a clone url', function () {
    // The app JWT can mint an installation token for EVERY installation of the app. A
    // clone URL is handed to processes and written into `git remote`, so this must fail
    // loudly rather than produce a URL that leaks it.
    $credentials = GithubApp::for('123', appPrivateKey());

    expect(fn () => Git::githubApp($credentials)->cloneUrlForRepository('acme/api', 'acme', $credentials))
        ->toThrow(InvalidCredentialsException::class);
});

it('refuses an app-jwt endpoint called with an installation credential, and the reverse', function () {
    Http::preventStrayRequests();

    $installation = Git::github(GithubAppToken::for('123', '999', appPrivateKey()));
    $app = Git::githubApp(GithubApp::for('123', appPrivateKey()));

    // Without these guards each of these reaches GitHub and comes back as its own opaque
    // 403 ("a JSON web token could not be decoded"), which names neither cause nor place.
    expect(fn () => $installation->installation('1'))->toThrow(InvalidCredentialsException::class)
        ->and(fn () => $app->installationRepositories())->toThrow(InvalidCredentialsException::class);
});

it('keys the conditional cache per installation, not on an empty token value', function () {
    config()->set('git.cache.enabled', true);

    Http::fake([
        '*/app/installations/*/access_tokens' => Http::response([
            'token' => 'ghs_a',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ]),
        '*/installation/repositories*' => Http::response(
            ['total_count' => 0, 'repositories' => []],
            200,
            ['ETag' => '"one"'],
        ),
    ]);

    // Two DIFFERENT installations reading the same URL. The ETag cache used to be keyed
    // on the token VALUE, which is the empty string for every app credential — so both
    // landed on one entry, and the only thing stopping one account's repository list from
    // being served to the other was GitHub never answering 304 across accounts.
    Git::github(GithubAppToken::for('123', '111', appPrivateKey()))->installationRepositories();
    Git::github(GithubAppToken::for('123', '222', appPrivateKey()))->installationRepositories();

    // The second installation is a cache MISS, so it must not replay the first's ETag.
    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('If-None-Match'));
});

it('mints a clone url through the fake so a consumer can assert it is authenticated', function () {
    $fake = Git::fake();

    $credentials = GithubAppToken::for('123', '999', appPrivateKey());

    expect($fake->github()->cloneUrlForRepository('acme/api', 'acme', $credentials))
        ->toContain('x-access-token:')
        ->and(fn () => $fake->github()->cloneUrlForRepository('acme/api', 'acme', GithubApp::for('123', appPrivateKey())))
        ->toThrow(InvalidCredentialsException::class);
});

it('authenticates githubApp() from config when no credential is passed', function () {
    config()->set('git.providers.github.app.id', '456');
    config()->set('git.providers.github.app.private_key', appPrivateKey());

    Http::fake(['*/app/installations/51234567' => Http::response(installationPayload())]);

    // The no-argument path: a consumer that configured the app once should not have to
    // rebuild the credential at every call site.
    expect(Git::githubApp()->installation('51234567')->accountLogin)->toBe('acme-inc');

    // Signed as the CONFIGURED app, not as the static GITHUB_TOKEN the same block holds.
    Http::assertSent(function (Request $request): bool {
        $authorization = str_replace('Bearer ', '', $request->header('Authorization')[0] ?? '');
        /** @var array<string, mixed> $payload */
        $payload = (array) json_decode(base64_decode(strtr(explode('.', $authorization)[1], '-_', '+/')), true);

        return $payload['iss'] === '456';
    });
});

it('names the missing app config key rather than failing as unauthenticated', function (string $missing) {
    // An operator reading a log needs WHICH key is absent. Falling back to `null`
    // credentials would surface the generic "requires authentication" instead, and an
    // unset private key would look identical to an unset app id.
    config()->set('git.providers.github.app.id', '456');
    config()->set('git.providers.github.app.private_key', appPrivateKey());
    config()->set("git.providers.github.app.{$missing}", null);

    expect(fn () => Git::githubApp())
        ->toThrow(InvalidCredentialsException::class, "git.providers.github.app.{$missing}");
})->with(['id', 'private_key']);

it('builds an install url through the fake', function () {
    fakeCredentials();

    $fake = Git::fake();

    expect($fake->githubApp()->installUrl('abc'))->toContain('installations/new?state=abc');
});
