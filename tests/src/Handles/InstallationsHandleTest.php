<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Crypto\Signature\Key\RsaKey;
use RoundlyConsulting\Git\Dto\Credentials\GithubApp;
use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\Handles\InstallationsHandle;

function handleInstallation(string $login = 'acme-inc'): Installation
{
    return new Installation(
        provider: ProviderName::Github,
        id: '51234567',
        accountLogin: $login,
        accountType: 'Organization',
        repositorySelection: 'all',
    );
}

it('drives every installation lookup through the handle', function (): void {
    fakeCredentials();

    $fake = Git::fake();
    $fake->github()->seedInstallation(handleInstallation());

    $installations = Git::githubApp()->installations();

    expect($installations)->toBeInstanceOf(InstallationsHandle::class)
        ->and($installations->all(10)->items)->toHaveCount(1)
        ->and($installations->find('51234567')->accountLogin)->toBe('acme-inc')
        ->and($installations->forOrganization('acme-inc')->id)->toBe('51234567')
        ->and($installations->forUser('octocat')->id)->toBe('51234567')
        ->and($installations->installUrl('state-1'))->toEndWith('/installations/new?state=state-1');

    $fake->assertSent(ProviderName::Github, 'listInstallations', fn (int $perPage): bool => $perPage === 10);
    $fake->assertSent(ProviderName::Github, 'installation', fn (string $id): bool => $id === '51234567');
    $fake->assertSent(ProviderName::Github, 'organizationInstallation', fn (string $org): bool => $org === 'acme-inc');
    $fake->assertSent(ProviderName::Github, 'userInstallation', fn (string $login): bool => $login === 'octocat');
    $fake->assertSent(ProviderName::Github, 'installUrl', fn (?string $state): bool => $state === 'state-1');
});

it('looks an installation up as the app against the real endpoint', function (): void {
    Http::fake(['*/app/installations/42' => Http::response([
        'id' => 42,
        'account' => ['login' => 'acme-inc', 'type' => 'Organization'],
        'repository_selection' => 'all',
        'permissions' => [],
    ])]);

    $app = Git::githubApp(GithubApp::for('123', RsaKey::generate()->privatePem()));

    expect($app->installations()->find('42')->accountLogin)->toBe('acme-inc');
});

it('names the wrong credential when driven with an installation token', function (): void {
    github()->installations()->find('42');
})->throws(InvalidCredentialsException::class);

it('refuses identifiers that could step outside the lookup', function (): void {
    fakeCredentials();

    $fake = Git::fake();
    $installations = Git::githubApp()->installations();

    expect(fn () => $installations->find('42/../../repos'))->toThrow(OutOfScopeException::class, 'installation id')
        ->and(fn () => $installations->find(''))->toThrow(OutOfScopeException::class)
        ->and(fn () => $installations->forOrganization('acme/repos'))->toThrow(OutOfScopeException::class, 'organization')
        ->and(fn () => $installations->forUser('..'))->toThrow(OutOfScopeException::class, 'login')
        ->and(fn () => $installations->forUser('oct ocat'))->toThrow(OutOfScopeException::class);

    $fake->assertNothingSent();
});
