<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Git\GitServiceProvider;
use RoundlyConsulting\Git\Registry;

it('merges the package config', function (): void {
    expect(config('git.providers.github.url'))->toBe('https://api.github.com')
        ->and(config('git.batch.concurrency'))->toBe(25);
});

it('binds the registry as a singleton', function (): void {
    expect(app(Registry::class))->toBe(app(Registry::class));
});

it('registers the package commands', function (): void {
    expect(Artisan::all())
        ->toHaveKey('git:repos')
        ->toHaveKey('git:rate-limit')
        ->toHaveKey('git:commits')
        ->toHaveKey('git:webhook');
});

it('publishes the config under the git-config tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(GitServiceProvider::class, 'git-config');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('config/git.php')
        ->and(array_values($paths)[0])->toEndWith('config/git.php');
});

it('publishes the webhook routes under the git-routes tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(GitServiceProvider::class, 'git-routes');

    expect($paths)->toHaveCount(1)
        ->and(array_key_first($paths))->toEndWith('routes/git-webhooks.php')
        ->and(array_values($paths)[0])->toEndWith('routes/git-webhooks.php');
});

it('contributes a section to the about command', function (): void {
    config()->set('git.providers.github.token', null);
    config()->set('git.providers.gitlab.token', null);
    config()->set('git.providers.bitbucket.token', null);

    // No default credentials, every provider still paced, webhooks off.
    $this->artisan('about', ['--only' => 'git'])
        ->expectsOutputToContain('NONE')
        ->expectsOutputToContain('github, gitlab, bitbucket')
        ->assertSuccessful();
});

it('reports credentialed providers and disabled throttling to the about command', function (): void {
    config()->set('git.providers.github.token', 'ghp_token');
    config()->set('git.providers.gitlab.token', null);
    config()->set('git.providers.bitbucket.token', null);
    config()->set('git.providers.github.rateLimits.enabled', false);
    config()->set('git.providers.gitlab.rateLimits.enabled', false);
    config()->set('git.providers.bitbucket.rateLimits.enabled', false);
    config()->set('git.webhooks.enabled', true);
    config()->set('git.cache.enabled', true);

    $this->artisan('about', ['--only' => 'git'])
        ->expectsOutputToContain('OFF')
        ->expectsOutputToContain('git/webhooks')
        ->assertSuccessful();
});
