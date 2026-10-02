<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\Handles\RepositoryHandle;
use RoundlyConsulting\Git\Providers\Bitbucket;
use RoundlyConsulting\Git\Providers\Github;
use RoundlyConsulting\Git\Providers\Gitlab;

it('returns github instance using facade', function () {
    $credentials = new Token(
        new SensitiveParameterValue('d9297f39-3716-44e6-9d58-988edbfd1cfa'),
    );

    expect(Git::github($credentials))->toBeInstanceOf(Github::class);
});

it('resolves a provider by enum, class-string and string name', function () {
    expect(Git::provider(ProviderName::Github))->toBeInstanceOf(Github::class)
        ->and(Git::provider(Github::class))->toBeInstanceOf(Github::class)
        ->and(Git::provider(Gitlab::class))->toBeInstanceOf(Gitlab::class)
        ->and(Git::provider('bitbucket'))->toBeInstanceOf(Bitbucket::class);
});

it('returns unauthenticated provider when no credentials are configured', function () {
    expect(Git::provider(ProviderName::Github)->isAuthenticated())->toBeFalse();
});

it('uses config token as default credentials', function () {
    config()->set('git.providers.github.token', 'ghp_configured');

    Http::fake(['*/user' => snapshot('github/user')]);

    $github = Git::github();

    expect($github->isAuthenticated())->toBeTrue();

    $github->user();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer ghp_configured'));
});

it('lets an explicit credential override the config token', function () {
    config()->set('git.providers.github.token', 'ghp_configured');

    Http::fake(['*/user' => snapshot('github/user')]);

    Git::github(Token::from('ghp_explicit'))->user();

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer ghp_explicit'));
});

it('runs the readme macro over a built-in driver', function () {
    Git::macro('readmeApp', fn (): RepositoryHandle => $this->github()->repo('acme/app'));

    expect(Git::readmeApp())->toBeInstanceOf(RepositoryHandle::class)
        ->and(Git::readmeApp()->path())->toBe('acme/app');
});

it('swaps a built-in driver for a container-bound subclass, fake included', function () {
    app()->bind(Github::class, ReadmeAcmeGithub::class);

    expect(Git::github())->toBeInstanceOf(ReadmeAcmeGithub::class)
        ->and(Git::capabilities(ProviderName::Github))->toBe((new ReadmeAcmeGithub)->capabilities())
        ->and(Git::fake()->github()->features())->toBe((new ReadmeAcmeGithub)->features());
});

it('refuses a provider it has no driver for', function () {
    expect(fn () => Git::provider('App\\MyProvider'))->toThrow(ValueError::class);
});

/** The README's "swap a built-in driver" example: a subclass that adds to GitHub. */
class ReadmeAcmeGithub extends Github {}
