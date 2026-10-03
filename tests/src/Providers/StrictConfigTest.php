<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Concerns\InteractsWithRateLimits;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\GitServiceProvider;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * A typo in the host's git config fails loudly. The ones that mattered: a `timespan` typo
 * silently paced per minute, junk `max_wait` / `jitter` were dropped, and an unset webhook
 * switch loaded the route while `url()` and `about` both reported it off.
 */
function gitLimiterFor(string $provider): ?RateLimit
{
    $probe = new class
    {
        use InteractsWithRateLimits;

        public function limiter(string $provider): ?RateLimit
        {
            return $this->rateLimiter($provider);
        }
    };

    return $probe->limiter($provider);
}

/** Boot a fresh provider against the current config and report whether it loaded the route. */
function strictBootsWebhookRoute(): bool
{
    $provider = new GitServiceProvider(app());
    $provider->register();
    $provider->boot();

    app('router')->getRoutes()->refreshNameLookups();

    return app('router')->getRoutes()->getByName('git.webhooks') !== null;
}

it('refuses a typo in the rate-limit timespan instead of pacing per minute (strict config)', function (mixed $timespan): void {
    config()->set('git.providers.github.rateLimits.timespan', $timespan);

    expect(fn () => gitLimiterFor('github'))
        ->toThrow(InvalidConfigurationException::class, 'git.providers.github.rateLimits.timespan');
})->with(['hourly', 'Hour', '', 3600]);

it('reads the timespan strictly and defaults an absent one to minute (strict config)', function (): void {
    config()->set('git.providers.gitlab.rateLimits.timespan', 'day');

    expect(gitLimiterFor('gitlab')?->getTimespan())->toBe('day');

    config()->set('git.providers.gitlab.rateLimits.timespan', null);

    expect(gitLimiterFor('gitlab')?->getTimespan())->toBe('minute');
});

it('refuses a junk max_wait or jitter instead of ignoring it (strict config)', function (string $leaf, mixed $value): void {
    config()->set("git.providers.github.rateLimits.{$leaf}", $value);

    expect(fn () => gitLimiterFor('github'))
        ->toThrow(InvalidConfigurationException::class, "git.providers.github.rateLimits.{$leaf}");
})->with(['max_wait', 'jitter'])->with(['soon', '2.5', '', '-1']);

it('accepts a canonical max_wait and jitter, and null for neither (strict config)', function (): void {
    config()->set('git.providers.github.rateLimits.max_wait', '500');
    config()->set('git.providers.github.rateLimits.jitter', null);

    expect(gitLimiterFor('github'))->toBeInstanceOf(RateLimit::class);
});

it('refuses a blank or non-string rate-limit owner (strict config)', function (mixed $owner): void {
    config()->set('git.providers.bitbucket.rateLimits.owner', $owner);

    expect(fn () => gitLimiterFor('bitbucket'))
        ->toThrow(InvalidConfigurationException::class, 'git.providers.bitbucket.rateLimits.owner');
})->with(['blank' => '', 'spaces' => ' ', 'array' => [['app']]]);

it('keeps the webhook route off for an unset switch, agreeing with url() and about (strict config)', function (): void {
    config()->set('git.webhooks.enabled', null);

    Http::fake(['*/repos/acme/api/hooks' => Http::response([])]);

    expect(strictBootsWebhookRoute())->toBeFalse()
        ->and(fn () => github()->repo('acme/api')->webhooks()->register())
        ->toThrow(InvalidArgumentException::class, 'enable [git.webhooks.enabled]');

    Artisan::call('about', ['--only' => 'git']);

    expect(Artisan::output())->toMatch('/Webhooks\s*\.+\s*OFF/');
});

it('refuses a blank or non-string webhook path instead of mounting at the root (strict config)', function (mixed $path): void {
    config()->set('git.webhooks.enabled', true);
    config()->set('git.webhooks.path', $path);

    expect(fn () => strictBootsWebhookRoute())
        ->toThrow(InvalidConfigurationException::class, 'git.webhooks.path');
})->with(['blank' => '', 'array' => [['git']]]);

it('mounts the webhook route at the default path when the path is unset (strict config)', function (): void {
    config()->set('git.webhooks.enabled', true);
    config()->set('git.webhooks.path', null);

    expect(strictBootsWebhookRoute())->toBeTrue()
        ->and(app('router')->getRoutes()->getByName('git.webhooks')?->uri())->toBe('git/webhooks/{provider}');
});

it('refuses a blank or non-string api url instead of calling the public forge (strict config)', function (mixed $url): void {
    config()->set('git.providers.github.url', $url);
    Http::fake();

    expect(fn () => github()->user())
        ->toThrow(InvalidConfigurationException::class, 'git.providers.github.url');

    Http::assertNothingSent();
})->with(['blank' => '', 'int' => 443]);

it('refuses a blank or non-string cache store (strict config)', function (mixed $store): void {
    config()->set('git.cache.enabled', true);
    config()->set('git.cache.store', $store);
    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'octocat'], 200, ['ETag' => '"abc"'])]);

    expect(fn () => github()->user())
        ->toThrow(InvalidConfigurationException::class, 'git.cache.store');
})->with(['blank' => '', 'array' => [['array']]]);

it('refuses a blank or non-string logging channel (strict config)', function (mixed $channel): void {
    config()->set('git.logging.enabled', true);
    config()->set('git.logging.channel', $channel);
    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'octocat'])]);

    expect(fn () => github()->user())
        ->toThrow(InvalidConfigurationException::class, 'git.logging.channel');
})->with(['blank' => '', 'int' => 1]);

it('refuses a non-string github app id instead of falling back to the token (strict config)', function (mixed $id): void {
    config()->set('git.providers.github.token', 'ghp_static');
    config()->set('git.providers.github.app.id', $id);
    config()->set('git.providers.github.app.installation_id', '999');
    config()->set('git.providers.github.app.private_key', generateRsaKeypair()[0]);

    expect(fn () => Git::credentials('github'))
        ->toThrow(InvalidConfigurationException::class, '[git.providers.github.app.id]');
})->with(['int' => 12345, 'array' => [['12345']], 'bool' => true]);

it('still reads an unset or blank github app id as not configured (strict config)', function (mixed $id): void {
    config()->set('git.providers.github.token', 'ghp_static');
    config()->set('git.providers.github.app.id', $id);

    expect(Git::credentials('github'))->toBeInstanceOf(Token::class);
})->with(['unset' => null, 'blank' => '']);
