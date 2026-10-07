<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Git\Dto\Credentials\OauthToken;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\Input\NewBranch;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Facades\Git;

function fakeRepository(string $name = 'Hello-World'): Repository
{
    return new Repository(
        provider: ProviderName::Github,
        id: '1',
        path: "octocat/{$name}",
        name: $name,
        description: null,
        defaultBranch: 'main',
        owner: new Owner(id: '1', name: 'octocat', avatar: null),
        createdAt: Carbon::now(),
        lastActivityAt: Carbon::now(),
    );
}

it('returns seeded repositories and records the call', function () {
    $fake = Git::fake();
    $fake->github()->seedRepositories([fakeRepository()]);

    $repositories = Git::github()->repositories();

    expect($repositories->first())->toBeInstanceOf(Repository::class)->name->toBe('Hello-World');

    $fake->assertSent(ProviderName::Github, 'repositories');
    $fake->assertNotSent(ProviderName::Github, 'createRepository');
});

it('seeds a user and a single repository', function () {
    $fake = Git::fake();
    $fake->github()
        ->seedUser(new Owner(id: '9', name: 'jane', avatar: null))
        ->seedRepository(fakeRepository('one'));

    expect(Git::github()->user()->name)->toBe('jane')
        ->and(Git::github()->repository('octocat/one')->name)->toBe('one');
});

it('records and asserts created repositories', function () {
    fakeCredentials();

    $fake = Git::fake();

    Git::github()->createRepository(new NewRepository('acme'));

    $fake->assertRepositoryCreated('acme');
    $fake->assertSent(ProviderName::Github, 'createRepository');
});

it('resolves fakes via the provider method and lazily', function () {
    $fake = Git::fake();
    $fake->fakeFor(ProviderName::Gitlab)->seedRepositories([fakeRepository()]);

    expect(Git::provider('gitlab')->allRepositories()->count())->toBe(1);
});

it('asserts nothing sent on a fresh fake', function () {
    Git::fake()->assertNothingSent();
});

it('fails assertSent when the method was never called', function () {
    $fake = Git::fake();

    expect(fn () => $fake->assertSent(ProviderName::Bitbucket, 'repositories'))
        ->toThrow(AssertionFailedError::class);
});

it('narrows the repository-created assertion to owner and template', function () {
    fakeCredentials();

    $fake = Git::fake();

    $fake->github()->createRepository(new NewRepository(
        name: 'widget',
        owner: 'acme',
        template: 'roundly-consulting/package-template',
    ));

    $fake->assertRepositoryCreated('widget');
    $fake->assertRepositoryCreated('widget', owner: 'acme');
    $fake->assertRepositoryCreated('widget', owner: 'acme', template: 'roundly-consulting/package-template');
});

it('fails the repository-created assertion when it landed under another owner', function () {
    fakeCredentials();

    $fake = Git::fake();

    $fake->github()->createRepository(new NewRepository(name: 'widget', owner: 'acme'));

    // "Created" and "created in the right organization" are different claims — the whole
    // reason the assertion takes an owner.
    $fake->assertRepositoryCreated('widget', owner: 'other-org');
})->throws(AssertionFailedError::class);

describe('credential parity', function (): void {
    function parityInstallation(): Installation
    {
        return new Installation(
            provider: ProviderName::Github,
            id: '51234567',
            accountLogin: 'acme-inc',
            accountType: 'Organization',
            repositorySelection: 'selected',
            permissions: ['contents' => 'write'],
        );
    }

    it('refuses a credential type the real driver does not take', function () {
        Git::fake();

        expect(fn () => Git::bitbucket(OauthToken::for('a', 'r', 'c', 's', 'https://token.test')))
            ->toThrow(InvalidCredentialsException::class, 'Authentication with [OauthToken] is not supported by provider [Bitbucket]');
    });

    it('answers authenticationMethods() with the real driver list', function (ProviderName $name) {
        $fake = Git::fake();

        expect($fake->fakeFor($name)->authenticationMethods())->toBe(app($name->providerClass())->authenticationMethods());
    })->with([ProviderName::Github, ProviderName::Gitlab, ProviderName::Bitbucket]);

    it('refuses app lookups with an installation-side credential', function () {
        $fake = Git::fake();
        $fake->fakeFor(ProviderName::Github)->seedInstallation(parityInstallation());

        expect(fn () => Git::github(Token::from('t'))->installations()->find('1'))
            ->toThrow(InvalidCredentialsException::class, 'requires [GithubApp] credentials, but [Token] was supplied');

        $fake->assertNotSent(ProviderName::Github, 'installation');
    });

    it('refuses installation repositories with anything but an installation token', function () {
        Git::fake();

        expect(fn () => Git::github(Token::from('t'))->installationRepositories())
            ->toThrow(InvalidCredentialsException::class, 'requires [GithubAppToken] credentials');
    });

    it('names the missing app key when githubApp() has no app configured', function () {
        Git::fake();

        expect(fn () => Git::githubApp())->toThrow(InvalidCredentialsException::class, 'git.providers.github.app.id');
    });

    it('drives the app flow once fake app keys are configured', function () {
        config()->set('git.providers.github.app.id', '123');
        config()->set('git.providers.github.app.private_key', 'fake-key');

        $fake = Git::fake();
        $fake->fakeFor(ProviderName::Github)->seedInstallation(parityInstallation());

        expect(Git::githubApp()->installations()->find('51234567')->accountLogin)->toBe('acme-inc');

        $fake->assertSent(ProviderName::Github, 'installation');
    });

    it('refuses a write with no credential, as production does', function () {
        $fake = Git::fake();

        expect(Git::github()->isAuthenticated())->toBeFalse()
            ->and(fn () => Git::github()->repo('acme/app')->createBranch(new NewBranch('feature', 'main')))
            ->toThrow(InvalidCredentialsException::class, 'requires authentication');

        $fake->assertNotSent(ProviderName::Github, 'createBranch');
    });

    it('authenticates with the configured token when none is passed', function () {
        config()->set('git.providers.github.token', 'ghp_test');

        $fake = Git::fake();

        expect(Git::github()->isAuthenticated())->toBeTrue()
            ->and(Git::github()->repo('acme/app')->createBranch(new NewBranch('feature', 'main')))->toBe('refs/heads/feature');

        $fake->assertSent(ProviderName::Github, 'createBranch');
    });

    it('still reads anonymously with no credential', function () {
        $fake = Git::fake();
        $fake->fakeFor(ProviderName::Github)->seedRepositories([fakeRepository()]);

        expect(Git::github()->repositories()->items)->toHaveCount(1);
    });

    it('keeps each call its own credential while sharing seeds and records', function () {
        config()->set('git.providers.github.app.id', '123');
        config()->set('git.providers.github.app.private_key', 'fake-key');

        $fake = Git::fake();
        $app = Git::githubApp();
        $user = Git::github(Token::from('t'));

        $user->seedInstallation(parityInstallation());

        expect($app->installations()->find('51234567')->id)->toBe('51234567')
            ->and(fn () => $user->installations()->find('51234567'))->toThrow(InvalidCredentialsException::class);

        $fake->assertSentTimes(ProviderName::Github, 'installation', 1);
    });
});
