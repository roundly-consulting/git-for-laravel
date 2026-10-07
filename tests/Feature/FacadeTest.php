<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Git\Dto\Credentials\GithubApp;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Enums\MergeMethod;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\GitManager;
use RoundlyConsulting\Git\Providers\Bitbucket;
use RoundlyConsulting\Git\Providers\Github;
use RoundlyConsulting\Git\Providers\Gitlab;
use RoundlyConsulting\Git\Testing\GitFake;
use RoundlyConsulting\Git\Testing\ProviderFake;
use RoundlyConsulting\Git\Testing\RecordedCall;

/**
 * No `toReachEveryAction()`: git is a remote-API client with no `src/Actions` — its
 * behaviour lives in the drivers the manager hands out, reached through `repo()` /
 * `installations()` handles (the skill's remote-API-client shape).
 */
it('documents its root and is fakeable', function (): void {
    expect(Git::class)
        ->toDocumentItsRoot()
        ->toBeFakeable();
});

describe('the manager', function (): void {
    it('is the container singleton behind the facade', function (): void {
        expect(app(GitManager::class))->toBe(app(GitManager::class))
            ->and(Git::getFacadeRoot())->toBe(app(GitManager::class));
    });

    it('works injected, without the facade', function (): void {
        config()->set('git.providers.github.token', 'ghp_injected');

        Http::fake(['*/repos/acme/app/pulls/12/merge' => Http::response(['merged' => true, 'sha' => 'abc123'])]);

        $git = app(GitManager::class);

        expect($git->github()->repo('acme/app')->pullRequest(12)->merge(MergeMethod::Squash))->toBe('abc123');

        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/repos/acme/app/pulls/12/merge')
            && $request['merge_method'] === 'squash'
            && $request->hasHeader('Authorization', 'Bearer ghp_injected'));
    });

    it('declares the Git alias for package discovery', function (): void {
        // Discovery is off under Testbench, so the declaration is what is pinned.
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true);

        expect($composer['extra']['laravel']['aliases'] ?? [])->toBe(['Git' => Git::class]);
    });
});

describe('the flat facade methods', function (): void {
    it('hands out each driver', function (): void {
        expect(Git::github())->toBeInstanceOf(Github::class)
            ->and(Git::gitlab())->toBeInstanceOf(Gitlab::class)
            ->and(Git::bitbucket())->toBeInstanceOf(Bitbucket::class)
            ->and(Git::provider(ProviderName::Gitlab))->toBeInstanceOf(Gitlab::class)
            ->and(Git::githubApp(GithubApp::for('123', generateRsaKeypair()[0]))->isAuthenticated())->toBeTrue();
    });

    it('reports capabilities without authenticating', function (): void {
        expect(Git::capabilities('gitlab'))->toBeArray()->not->toBeEmpty();
    });

    it('answers the configured static token as the credential', function (): void {
        config()->set('git.providers.gitlab.token', 'glpat_configured');

        $credentials = Git::credentials(ProviderName::Gitlab);

        expect($credentials)->toBeInstanceOf(Token::class)
            ->and($credentials?->credentials?->getValue())->toBe('glpat_configured');
    });

    it('prefers the configured app installation over the static token', function (): void {
        config()->set('git.providers.github.token', 'ghp_static');
        config()->set('git.providers.github.app.id', '123');
        config()->set('git.providers.github.app.installation_id', '999');
        config()->set('git.providers.github.app.private_key', generateRsaKeypair()[0]);

        $credentials = Git::credentials('github');

        expect($credentials)->toBeInstanceOf(GithubAppToken::class)
            ->and($credentials instanceof GithubAppToken ? $credentials->installationId : null)->toBe('999');
    });

    it('falls back to the static token until all three app keys are configured', function (): void {
        config()->set('git.providers.github.token', 'ghp_static');
        config()->set('git.providers.github.app.id', '123');
        config()->set('git.providers.github.app.private_key', generateRsaKeypair()[0]);

        // id + key without an installation id is the `githubApp()`-only setup, not an error.
        expect(Git::credentials('github'))->toBeInstanceOf(Token::class)
            ->and(Git::credentials('github')?->credentials?->getValue())->toBe('ghp_static');
    });

    it('reads the live installation token off the configured credential', function (): void {
        config()->set('git.providers.github.app.id', '123');
        config()->set('git.providers.github.app.installation_id', '999');
        config()->set('git.providers.github.app.private_key', generateRsaKeypair()[0]);

        Http::fake(['*/app/installations/999/access_tokens' => Http::response([
            'token' => 'ghs_minted',
            'expires_at' => Carbon::now()->addHour()->toIso8601String(),
        ])]);

        $credentials = Git::credentials(ProviderName::Github);

        expect($credentials instanceof GithubAppToken ? $credentials->accessToken() : null)->toBe('ghs_minted');
    });

    it('answers null when nothing is configured', function (): void {
        config()->set('git.providers.bitbucket.token', null);

        expect(Git::credentials(ProviderName::Bitbucket))->toBeNull();
    });

    it('verifies an inbound webhook for a host-owned route', function (): void {
        // The frozen vector from the SignatureVerifier suite: body + secret → signature.
        $body = '{"action":"opened","number":42,"repository":{"full_name":"roundly-consulting/git-for-laravel"}}';
        config()->set('git.providers.github.webhook_secret', 'It is a secret to everybody');

        $signed = Request::create('/hooks/github', 'POST', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256=921d93384790fc126d687486d44feacee8c445b8206d85d8f42e5ca55234d90c',
        ], $body);
        $forged = Request::create('/hooks/github', 'POST', [], [], [], [
            'HTTP_X-Hub-Signature-256' => 'sha256='.str_repeat('0', 64),
        ], $body);

        expect(Git::verifyWebhook(ProviderName::Github, $signed))->toBeTrue()
            ->and(Git::verifyWebhook('github', $forged))->toBeFalse();
    });

    it('verifies nothing when no secret is configured', function (): void {
        config()->set('git.providers.gitlab.webhook_secret', null);

        $request = Request::create('/hooks/gitlab', 'POST', [], [], [], ['HTTP_X-Gitlab-Token' => 'anything'], '{}');

        expect(Git::verifyWebhook(ProviderName::Gitlab, $request))->toBeFalse();
    });

    it('reads a blank secret as not configured, so a matching blank token verifies nothing', function (string $blank): void {
        config()->set('git.providers.gitlab.webhook_secret', $blank);

        $request = Request::create('/hooks/gitlab', 'POST', [], [], [], ['HTTP_X-Gitlab-Token' => '  '], '{}');

        expect(Git::verifyWebhook(ProviderName::Gitlab, $request))->toBeFalse();
    })->with(['empty' => '', 'whitespace' => '  ']);
});

describe('the fake', function (): void {
    beforeEach(function (): void {
        // The fake authenticates as production does, so a write needs a credential configured.
        fakeCredentials();
    });

    it('swaps a manager subtype in for the facade and for injection', function (): void {
        $fake = Git::fake();
        $fake->fakeFor(ProviderName::Gitlab)->seedBranches(['main']);

        expect($fake)->toBeInstanceOf(GitFake::class)
            ->and(app(GitManager::class))->toBe($fake)
            ->and(Git::github())->toBeInstanceOf(ProviderFake::class)
            ->and(Git::githubApp()->providerName())->toBe(ProviderName::Github)
            ->and(Git::provider(Bitbucket::class)->providerName())->toBe(ProviderName::Bitbucket)
            // Each call carries its own credential, over the provider's one set of seeds.
            ->and(Git::gitlab()->branches('g/p')->items)->toBe(['main']);
    });

    it('records calls made through the handles', function (): void {
        $fake = Git::fake();

        Git::github()->repo('acme/app')->pullRequest(12)->merge();
        Git::github()->repo('acme/app')->pullRequest(12)->approve('LGTM');

        $fake->assertSent(ProviderName::Github, 'mergePullRequest', fn (string $path, int $number): bool => $path === 'acme/app' && $number === 12);
        $fake->assertSentTimes(ProviderName::Github, 'approvePullRequest', 1);
        $fake->assertNotSent(ProviderName::Github, 'closePullRequest');
        $fake->assertNothingSent(ProviderName::Gitlab);

        expect(Git::recorded(ProviderName::Github))->toHaveCount(2)
            ->and(Git::recorded(ProviderName::Github, 'mergePullRequest')[0])->toBeInstanceOf(RecordedCall::class)
            ->and(Git::recorded(ProviderName::Github, 'mergePullRequest')[0]->arguments[1])->toBe(12);
    });

    it('asserts through the facade itself', function (): void {
        Git::fake();

        Git::gitlab()->repo('group/project')->branches();

        Git::assertSent(ProviderName::Gitlab, 'branches');
        Git::assertNothingSent(ProviderName::Github);
    });

    it('drives the git:webhook command through the fake', function (): void {
        $fake = Git::fake();

        $this->artisan('git:webhook github acme/app --url=https://app.test/hook')->assertExitCode(0);

        $fake->assertSent(ProviderName::Github, 'createWebhook', fn (string $path): bool => $path === 'acme/app');
    });

    it('fails assertSent when the call never happened', function (): void {
        $fake = Git::fake();

        expect(fn () => $fake->assertSent(ProviderName::Bitbucket, 'repositories'))->toThrow(AssertionFailedError::class);
    });

    it('fails assertSent when the arguments do not match', function (): void {
        $fake = Git::fake();

        Git::github()->repo('acme/app')->pullRequest(12)->merge();

        expect(fn () => $fake->assertSent(ProviderName::Github, 'mergePullRequest', fn (string $path, int $number): bool => $number === 99))
            ->toThrow(AssertionFailedError::class);
    });

    it('fails assertSentTimes on a different count', function (): void {
        $fake = Git::fake();

        Git::github()->repo('acme/app')->branches();
        Git::github()->repo('acme/app')->branches();

        $fake->assertSentTimes(ProviderName::Github, 'branches', 2);

        expect(fn () => $fake->assertSentTimes(ProviderName::Github, 'branches', 1))->toThrow(AssertionFailedError::class);
    });

    it('fails assertNotSent when the call happened', function (): void {
        $fake = Git::fake();

        Git::github()->repo('acme/app')->pullRequest(3)->close();

        $fake->assertNotSent(ProviderName::Github, 'closePullRequest', fn (string $path, int $number): bool => $number === 4);

        expect(fn () => $fake->assertNotSent(ProviderName::Github, 'closePullRequest'))->toThrow(AssertionFailedError::class);
    });

    it('fails assertNothingSent once anything was sent', function (): void {
        $fake = Git::fake();

        $fake->assertNothingSent();

        Git::bitbucket()->repositories();

        expect(fn () => $fake->assertNothingSent())->toThrow(AssertionFailedError::class)
            ->and(fn () => $fake->assertNothingSent(ProviderName::Bitbucket))->toThrow(AssertionFailedError::class);
    });

    it('asserts batched and not-batched calls', function (): void {
        $fake = Git::fake();

        Git::github()->batch()->languages(['acme/app']);

        $fake->assertBatched(ProviderName::Github, 'languages');
        $fake->assertNotBatched(ProviderName::Github, 'repositories');

        expect(fn () => $fake->assertBatched(ProviderName::Github, 'repositories'))->toThrow(AssertionFailedError::class)
            ->and(fn () => $fake->assertNotBatched(ProviderName::Github, 'languages'))->toThrow(AssertionFailedError::class);
    });

    it('asserts created and not-created repositories', function (): void {
        $fake = Git::fake();

        $fake->assertNoRepositoryCreated();

        Git::github()->createRepository(new NewRepository('widget', owner: 'acme'));

        $fake->assertRepositoryCreated('widget', owner: 'acme');

        expect(fn () => $fake->assertNoRepositoryCreated())->toThrow(AssertionFailedError::class)
            ->and(fn () => $fake->assertRepositoryCreated('gadget'))->toThrow(AssertionFailedError::class);
    });

    it('opens a repository handle from a returned repository', function (): void {
        $fake = Git::fake();

        $repository = new Repository(
            provider: ProviderName::Github,
            id: '1',
            path: 'acme/app',
            name: 'app',
            description: null,
            defaultBranch: 'main',
            owner: new Owner(id: '1', name: 'acme', avatar: null),
            createdAt: Carbon::now(),
            lastActivityAt: Carbon::now(),
        );

        expect(Git::github()->repo($repository)->path())->toBe('acme/app');

        Git::github()->repo($repository)->releases();

        $fake->assertSent(ProviderName::Github, 'releases', fn (string $path): bool => $path === 'acme/app');
    });
});
