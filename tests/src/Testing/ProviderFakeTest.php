<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Facades\Registry;

it('exposes a faithful provider double surface', function () {
    $fake = Registry::fake();
    $provider = $fake->github();

    expect($provider->name())->toBe('GitHub')
        ->and($provider->description())->toBe('GitHub Provider')
        ->and($provider->providerName())->toBe(ProviderName::Github)
        ->and($provider->isAuthenticated())->toBeTrue()
        ->and($provider->supports(Feature::ListRepositories))->toBeTrue()
        ->and($provider->features())->toBe(Feature::cases())
        ->and($provider->authenticationMethods())->toBe([])
        ->and($provider->rateLimit())->toBeNull()
        ->and($provider->authenticate(Token::from('x')))->toBe($provider);
});

it('returns seeded branches, commit and clone url', function () {
    $fake = Registry::fake();
    $commit = new Commit(ProviderName::Github, 'sha', 'msg', new Author('n', 'e', null), null, Carbon::now());

    $provider = $fake->github();
    $provider->seedBranches(['main'])->seedCommits([$commit])->seedCommit($commit);

    expect($provider->branches('o/r')->items)->toBe(['main'])
        ->and($provider->commit('o/r', 'sha')->sha)->toBe('sha')
        // The fake now carries the CREDENTIAL too: a double that always returned a
        // credential-less URL could not fail the way the real providers did.
        ->and($provider->cloneUrlForRepository('o/r', 'jane', Token::from('x')))->toBe('https://jane:x@fake/o/r.git')
        ->and($provider->user())->toBeInstanceOf(Owner::class);
});

it('throws when reading unseeded repository or commit', function () {
    $provider = Registry::fake()->github();

    expect(fn () => $provider->repository('o/r'))->toThrow(RuntimeException::class)
        ->and(fn () => $provider->commit('o/r', 'x'))->toThrow(RuntimeException::class);
});

it('exposes capabilities and the feature matrix on the fake', function () {
    $provider = Registry::fake()->github();

    expect($provider->capabilities())->toHaveCount(count(Feature::cases()))
        ->and($provider->supportsAll(Feature::CreateRelease))->toBeTrue()
        ->and($provider->supportsAny(Feature::CreateRelease))->toBeTrue()
        ->and($provider->supportsAny())->toBeFalse()
        ->and($provider->featureMatrix())->toHaveCount(count(Feature::cases()))
        ->and($provider->repositoryUrl('o/r'))->toBe('o/r')
        ->and($provider->languagesUrl('o/r'))->toBe('o/r')
        ->and($provider->pullRequestUrl('o/r', 7))->toBe('o/r#7')
        ->and($provider->contentsRequest('o/r', 'f', null))->toBe(['f', []])
        ->and($provider->normalizeLanguages(['PHP' => '90']))->toBe(['PHP' => 90])
        ->and($provider->runPool([]))->toBe([]);
});

it('drives the webhook lifecycle through the fake', function () {
    $fake = Registry::fake();
    $provider = $fake->github();
    $provider->seedWebhooks([new Webhook(
        provider: ProviderName::Github,
        id: '1',
        url: 'https://app.test/hook',
        events: ['push'],
        active: true,
    )]);

    $manager = $provider->webhooks('o/r');

    expect($manager->all())->toHaveCount(1)
        ->and($manager->registered('https://app.test/hook'))->toBeTrue();

    $created = $provider->createWebhook('o/r', new NewWebhook(url: 'https://new.test/hook'));
    $provider->deleteWebhook('o/r', '1');

    expect($created->url)->toBe('https://new.test/hook');

    $fake->assertSent(ProviderName::Github, 'createWebhook');
    $fake->assertSent(ProviderName::Github, 'deleteWebhook');
});

it('throws on unavailable fake mappers', function () {
    $provider = Registry::fake()->github();

    expect(fn () => $provider->mapResource())->toThrow(RuntimeException::class)
        ->and(fn () => $provider->mapFileContent([]))->toThrow(RuntimeException::class);
});

it('drives the installation flow without http', function () {
    // ONE fake: Registry::fake() rebinds a fresh double, so a second call would assert
    // against an instance that recorded nothing.
    $registry = Registry::fake();

    $registry->github()->seedInstallation(new Installation(
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

    expect(Registry::githubApp()->installation('51234567')->accountLogin)->toBe('acme-inc')
        ->and(Registry::github()->installationRepositories()->items)->toHaveCount(1)
        ->and(Registry::github()->allInstallationRepositories()->all())->toHaveCount(1);

    $registry->assertSent(ProviderName::Github, 'installation');
});

it('refuses an installation lookup nobody seeded', function () {
    Registry::fake();

    expect(fn () => Registry::githubApp()->installation('1'))->toThrow(RuntimeException::class);
});

it('drives the app-jwt lookups through the fake', function () {
    $registry = Registry::fake();

    $registry->githubApp()->seedInstallation(fakeInstallation());

    // Every app-jwt lookup answers the single seeded installation, and each records under
    // its OWN method name so a consumer can assert WHICH lookup its code performed.
    expect(Registry::githubApp()->organizationInstallation('acme-inc')->accountLogin)->toBe('acme-inc')
        ->and(Registry::githubApp()->userInstallation('octocat')->accountType)->toBe('Organization')
        // No explicit list seeded: the single installation stands in, rather than an empty
        // page that would read as "this app is installed nowhere".
        ->and(Registry::githubApp()->installations()->items)->toHaveCount(1);

    $registry->assertSent(ProviderName::Github, 'organizationInstallation');
    $registry->assertSent(ProviderName::Github, 'userInstallation');
    $registry->assertSent(ProviderName::Github, 'installations');
});

it('lists every seeded installation', function () {
    $registry = Registry::fake();

    $registry->githubApp()->seedInstallations([
        fakeInstallation(),
        fakeInstallation('octocat'),
    ]);

    $items = Registry::githubApp()->installations()->items;

    expect($items)->toHaveCount(2)
        ->and($items[1]->accountLogin)->toBe('octocat');
});

it('refuses an installation list nobody seeded', function () {
    Registry::fake();

    expect(Registry::githubApp()->installations()->items)->toBe([])
        ->and(fn () => Registry::githubApp()->organizationInstallation('acme-inc'))->toThrow(RuntimeException::class)
        ->and(fn () => Registry::githubApp()->userInstallation('octocat'))->toThrow(RuntimeException::class);
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
    $fake = Registry::fake();

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
    $repo = Registry::fake()->github()->createRepository(new NewRepository('widget'));

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

    $repo = Registry::fake()->github()
        ->seedCreatedRepository($seeded)
        ->createRepository(new NewRepository(name: 'widget', owner: 'acme', autoInit: true));

    expect($repo)->toBe($seeded);
});
