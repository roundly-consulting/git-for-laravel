<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Credentials\GithubApp;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Credentials\OauthToken;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\Testing\ProviderFake;

beforeEach(function (): void {
    // The fake authenticates as production does, so a write needs a credential configured.
    fakeCredentials();
});

it('exposes a faithful provider double surface', function () {
    $fake = Git::fake();
    $provider = $fake->github();

    expect($provider->name())->toBe('GitHub')
        ->and($provider->description())->toBe('GitHub Provider')
        ->and($provider->providerName())->toBe(ProviderName::Github)
        ->and($provider->isAuthenticated())->toBeTrue()
        ->and($provider->supports(Feature::ListRepositories))->toBeTrue()
        ->and($provider->features())->toBe(Feature::cases())
        ->and($provider->authenticationMethods())->toBe([Token::class, GithubAppToken::class, GithubApp::class, OauthToken::class])
        ->and($provider->rateLimit())->toBeNull()
        ->and($provider->authenticate(Token::from('x')))->toBe($provider);
});

it('returns seeded branches, commit and clone url', function () {
    $fake = Git::fake();
    $commit = new Commit(ProviderName::Github, 'sha', 'msg', new Author('n', 'e', null), null, Carbon::now());

    $provider = $fake->github();
    $provider->seedBranches(['main'])->seedCommits([$commit])->seedCommit($commit);

    expect($provider->branches('o/r')->items)->toBe(['main'])
        ->and($provider->commit('o/r', 'sha')->sha)->toBe('sha')
        // The fake now carries the CREDENTIAL too: a double that always returned a
        // credential-less URL could not fail the way the real providers did.
        ->and($provider->cloneUrlForRepository('o/r', 'jane', Token::from('x')))->toBe('https://token:x@fake/o/r.git')
        ->and($provider->user())->toBeInstanceOf(Owner::class);
});

it('throws when reading unseeded repository or commit', function () {
    $provider = Git::fake()->github();

    expect(fn () => $provider->repository('o/r'))->toThrow(RuntimeException::class)
        ->and(fn () => $provider->commit('o/r', 'x'))->toThrow(RuntimeException::class);
});

it('exposes capabilities and the feature matrix on the fake', function () {
    $provider = Git::fake()->github();

    expect($provider->capabilities())->toHaveCount(count(Feature::cases()))
        ->and($provider->supportsAll(Feature::CreateRelease))->toBeTrue()
        ->and($provider->supportsAny(Feature::CreateRelease))->toBeTrue()
        ->and($provider->supportsAny())->toBeFalse()
        ->and($provider->featureMatrix())->toHaveCount(count(Feature::cases()))
        ->and($provider->featureInfo())->toHaveCount(count(Feature::cases()));
});

it('drives the webhook lifecycle through the fake', function () {
    $fake = Git::fake();
    $provider = $fake->github();
    $provider->seedWebhooks([new Webhook(
        provider: ProviderName::Github,
        id: '1',
        url: 'https://app.test/hook',
        events: ['push'],
        active: true,
    )]);

    $manager = $provider->repo('o/r')->webhooks();

    expect($manager->all())->toHaveCount(1)
        ->and($manager->registered('https://app.test/hook'))->toBeTrue();

    $created = $provider->createWebhook('o/r', new NewWebhook(url: 'https://new.test/hook'));
    $provider->deleteWebhook('o/r', '1');

    expect($created->url)->toBe('https://new.test/hook');

    $fake->assertSent(ProviderName::Github, 'createWebhook');
    $fake->assertSent(ProviderName::Github, 'deleteWebhook');
});

it('keeps the batch plumbing off the fake', function () {
    // The URL builders, mappers and pool runner are the drivers' `@internal` batch
    // plumbing, not part of the Provider contract — so the double has nothing to fake.
    foreach (['mapResource', 'mapFileContent', 'runPool', 'repositoryUrl', 'languagesUrl', 'pullRequestUrl', 'contentsRequest', 'normalizeLanguages'] as $method) {
        expect(method_exists(ProviderFake::class, $method))->toBeFalse("ProviderFake still answers [{$method}].");
    }
});

it('drives the installation flow without http', function () {
    // With an installation configured, `Git::github()` authenticates as that installation
    // (an installation token) — the credential `installationRepositories()` requires.
    config()->set('git.providers.github.app.installation_id', '999');

    // ONE fake: Git::fake() rebinds a fresh double, so a second call would assert
    // against an instance that recorded nothing.
    $fake = Git::fake();

    $fake->github()->seedInstallation(new Installation(
        provider: ProviderName::Github,
        id: '51234567',
        accountLogin: 'acme-inc',
        accountType: 'Organization',
        repositorySelection: 'selected',
        permissions: ['contents' => 'write'],
    ))->seedRepositories([new Repository(
        provider: ProviderName::Github,
        id: '40823311',
        path: 'acme-inc/platform-api',
        name: 'platform-api',
        description: null,
        defaultBranch: 'main',
        owner: new Owner(id: '1', name: 'acme-inc', avatar: null),
        createdAt: Carbon::parse('2020-01-01'),
        lastActivityAt: Carbon::parse('2026-08-01'),
    )]);

    expect(Git::githubApp()->installation('51234567')->accountLogin)->toBe('acme-inc')
        ->and(Git::github()->installationRepositories()->items)->toHaveCount(1)
        ->and(Git::github()->allInstallationRepositories()->all())->toHaveCount(1);

    $fake->assertSent(ProviderName::Github, 'installation');
});

it('refuses an installation lookup nobody seeded', function () {
    Git::fake();

    expect(fn () => Git::githubApp()->installation('1'))->toThrow(RuntimeException::class);
});

it('drives the app-jwt lookups through the fake', function () {
    $fake = Git::fake();

    $fake->githubApp()->seedInstallation(fakeInstallation());

    // Every app-jwt lookup answers the single seeded installation, and each records under
    // its OWN method name so a consumer can assert WHICH lookup its code performed.
    expect(Git::githubApp()->organizationInstallation('acme-inc')->accountLogin)->toBe('acme-inc')
        ->and(Git::githubApp()->userInstallation('octocat')->accountType)->toBe('Organization')
        // No explicit list seeded: the single installation stands in, rather than an empty
        // page that would read as "this app is installed nowhere".
        ->and(Git::githubApp()->installations()->all()->items)->toHaveCount(1);

    $fake->assertSent(ProviderName::Github, 'organizationInstallation');
    $fake->assertSent(ProviderName::Github, 'userInstallation');
    $fake->assertSent(ProviderName::Github, 'listInstallations');
});

it('lists every seeded installation', function () {
    $fake = Git::fake();

    $fake->githubApp()->seedInstallations([
        fakeInstallation(),
        fakeInstallation('octocat'),
    ]);

    $items = Git::githubApp()->installations()->all()->items;

    expect($items)->toHaveCount(2)
        ->and($items[1]->accountLogin)->toBe('octocat');
});

it('refuses an installation list nobody seeded', function () {
    Git::fake();

    expect(Git::githubApp()->installations()->all()->items)->toBe([])
        ->and(fn () => Git::githubApp()->organizationInstallation('acme-inc'))->toThrow(RuntimeException::class)
        ->and(fn () => Git::githubApp()->userInstallation('octocat'))->toThrow(RuntimeException::class);
});

function fakeInstallation(string $login = 'acme-inc'): Installation
{
    return new Installation(
        provider: ProviderName::Github,
        id: '51234567',
        accountLogin: $login,
        accountType: 'Organization',
        repositorySelection: 'selected',
        permissions: ['contents' => 'write'],
    );
}

it('round-trips every repository-provisioning field', function () {
    $fake = Git::fake();

    $repo = $fake->github()->createRepository(new NewRepository(
        name: 'widget',
        private: true,
        description: 'd',
        owner: 'acme',
        template: 'roundly-consulting/package-template',
        autoInit: true,
        defaultBranch: 'develop',
    ));

    // The fake is where a consumer's whole test suite for this feature runs, so a field it
    // drops is a field that consumer can never assert on.
    expect($repo)->path->toBe('acme/widget')->name->toBe('widget')->defaultBranch->toBe('develop')
        ->and($repo->owner->name)->toBe('acme');

    $fake->assertRepositoryCreated('widget', owner: 'acme', template: 'roundly-consulting/package-template');
});

it('reports no default branch on a fake repository with no initial commit', function () {
    $repo = Git::fake()->github()->createRepository(new NewRepository('widget'));

    expect($repo->defaultBranch)->toBe('')->and($repo->path)->toBe('widget');
});

it('lets a seeded repository win over the provisioning input', function () {
    $seeded = new Repository(
        provider: ProviderName::Github,
        id: 'seeded',
        path: 'other/thing',
        name: 'thing',
        description: null,
        defaultBranch: 'trunk',
        owner: new Owner(id: 'o', name: 'other', avatar: null),
        createdAt: Carbon::now(),
        lastActivityAt: Carbon::now(),
    );

    $repo = Git::fake()->github()
        ->seedCreatedRepository($seeded)
        ->createRepository(new NewRepository(name: 'widget', owner: 'acme', autoInit: true));

    expect($repo)->toBe($seeded);
});

it('puts the username in a fake clone url that the real driver would', function (ProviderName $name, Closure $credential, string $url) {
    $fake = Git::fake();

    expect($fake->fakeFor($name)->cloneUrlForRepository('o/r', 'alice', $credential()))->toBe($url);
})->with([
    'github token' => [ProviderName::Github, fn () => Token::from('t'), 'https://token:t@fake/o/r.git'],
    'github installation token' => [ProviderName::Github, fn () => appCredentials(), 'https://x-access-token:ghs_fake@fake/o/r.git'],
    'gitlab token' => [ProviderName::Gitlab, fn () => Token::from('t'), 'https://oauth2:t@fake/o/r.git'],
    'gitlab oauth' => [ProviderName::Gitlab, fn () => OauthToken::for('a', 'r', 'c', 's', 'https://token.test'), 'https://oauth2:fake-refreshed@fake/o/r.git'],
    'bitbucket token' => [ProviderName::Bitbucket, fn () => Token::from('t'), 'https://alice:t@fake/o/r.git'],
]);
