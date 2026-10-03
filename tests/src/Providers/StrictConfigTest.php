<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Concerns\InteractsWithRateLimits;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\GitServiceProvider;
use RoundlyConsulting\Git\Support\Settings;
use RoundlyConsulting\HttpClientRateLimits\RateLimit;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * A typo in the host's git config fails loudly. The ones that mattered: a `timespan` typo
 * silently paced per minute, junk `max_wait` / `jitter` were dropped, and an unset webhook
 * switch loaded the route while `url()` and `about` both reported it off. A blank value (a
 * host's `KEY=`) is not set: it takes the default, or leaves an optional setting off.
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
})->with(['hourly', 'Hour', 3600]);

it('reads the timespan strictly and defaults an absent or blank one to minute (strict config)', function (?string $unset): void {
    config()->set('git.providers.gitlab.rateLimits.timespan', 'day');

    expect(gitLimiterFor('gitlab')?->getTimespan())->toBe('day');

    config()->set('git.providers.gitlab.rateLimits.timespan', $unset);

    expect(gitLimiterFor('gitlab')?->getTimespan())->toBe('minute');
})->with(['absent' => null, 'empty' => '', 'whitespace' => ' ']);

it('refuses a junk max_wait or jitter instead of ignoring it (strict config)', function (string $leaf, mixed $value): void {
    config()->set("git.providers.github.rateLimits.{$leaf}", $value);

    expect(fn () => gitLimiterFor('github'))
        ->toThrow(InvalidConfigurationException::class, "git.providers.github.rateLimits.{$leaf}");
})->with(['max_wait', 'jitter'])->with(['soon', '2.5', '-1']);

it('accepts a canonical max_wait and jitter, and null for neither (strict config)', function (): void {
    config()->set('git.providers.github.rateLimits.max_wait', '500');
    config()->set('git.providers.github.rateLimits.jitter', null);

    expect(gitLimiterFor('github'))->toBeInstanceOf(RateLimit::class);
});

it('reads a blank max_wait or jitter as not set, leaving it off (strict config)', function (string $blank): void {
    config()->set('git.providers.github.rateLimits.max_wait', $blank);
    config()->set('git.providers.github.rateLimits.jitter', $blank);

    expect(Settings::optionalInteger('git.providers.github.rateLimits.max_wait', $blank, 0, PHP_INT_MAX))->toBeNull()
        ->and(gitLimiterFor('github'))->toBeInstanceOf(RateLimit::class);
})->with(['empty' => '', 'whitespace' => '  ']);

it('refuses a non-string rate-limit owner (strict config)', function (mixed $owner): void {
    config()->set('git.providers.bitbucket.rateLimits.owner', $owner);

    expect(fn () => gitLimiterFor('bitbucket'))
        ->toThrow(InvalidConfigurationException::class, 'git.providers.bitbucket.rateLimits.owner');
})->with(['array' => [['app']], 'int' => 7]);

it('reads a blank rate-limit owner as not set, taking app (strict config)', function (string $blank): void {
    config()->set('git.providers.bitbucket.rateLimits.owner', $blank);

    expect(Settings::string('git.providers.bitbucket.rateLimits.owner', $blank, 'app'))->toBe('app')
        ->and(gitLimiterFor('bitbucket'))->toBeInstanceOf(RateLimit::class);
})->with(['empty' => '', 'whitespace' => ' ']);

it('keeps the webhook route off for an unset or blank switch, agreeing with url() and about (strict config)', function (?string $unset): void {
    config()->set('git.webhooks.enabled', $unset);

    Http::fake(['*/repos/acme/api/hooks' => Http::response([])]);

    expect(strictBootsWebhookRoute())->toBeFalse()
        ->and(fn () => github()->repo('acme/api')->webhooks()->register())
        ->toThrow(InvalidArgumentException::class, 'enable [git.webhooks.enabled]');

    Artisan::call('about', ['--only' => 'git']);

    expect(Artisan::output())->toMatch('/Webhooks\s*\.+\s*OFF/');
})->with(['absent' => null, 'empty' => '', 'whitespace' => ' ']);

it('refuses a non-string webhook path instead of mounting at the root (strict config)', function (mixed $path): void {
    config()->set('git.webhooks.enabled', true);
    config()->set('git.webhooks.path', $path);

    expect(fn () => strictBootsWebhookRoute())
        ->toThrow(InvalidConfigurationException::class, 'git.webhooks.path');
})->with(['array' => [['git']], 'int' => 5]);

it('mounts the webhook route at the default path when the path is unset or blank, never the root (strict config)', function (?string $unset): void {
    config()->set('git.webhooks.enabled', true);
    config()->set('git.webhooks.path', $unset);

    expect(strictBootsWebhookRoute())->toBeTrue()
        ->and(app('router')->getRoutes()->getByName('git.webhooks')?->uri())->toBe('git/webhooks/{provider}');
})->with(['absent' => null, 'empty' => '', 'whitespace' => ' ']);

it('refuses a slash-only webhook path instead of mounting at the root (strict config)', function (string $path): void {
    config()->set('git.webhooks.enabled', true);
    config()->set('git.webhooks.path', $path);

    // Before: `/` and `//` mounted the receiver at `{provider}`, the site root.
    expect(fn () => strictBootsWebhookRoute())
        ->toThrow(InvalidConfigurationException::class, 'Configuration value [git.webhooks.path] must be a path below the site root');
})->with(['slash' => '/', 'slashes' => '//', 'padded slash' => ' / ']);

it('trims surrounding slashes and whitespace from the webhook path (strict config)', function (string $path, string $uri): void {
    config()->set('git.webhooks.enabled', true);
    config()->set('git.webhooks.path', $path);

    expect(strictBootsWebhookRoute())->toBeTrue()
        ->and(app('router')->getRoutes()->getByName('git.webhooks')?->uri())->toBe($uri);

    Artisan::call('about', ['--only' => 'git']);

    expect(Artisan::output())->toMatch('/Webhooks\s*\.+\s*'.preg_quote(str_replace('/{provider}', '', $uri), '/').'\s/');
})->with([
    'trailing slash' => ['/hooks/', 'hooks/{provider}'],
    'leading slash' => ['/git/webhooks', 'git/webhooks/{provider}'],
    'padded' => [' hooks ', 'hooks/{provider}'],
    'plain' => ['hooks', 'hooks/{provider}'],
]);

it('refuses a non-string api url instead of calling the public forge (strict config)', function (mixed $url): void {
    config()->set('git.providers.github.url', $url);
    Http::fake();

    expect(fn () => github()->user())
        ->toThrow(InvalidConfigurationException::class, 'git.providers.github.url');

    Http::assertNothingSent();
})->with(['int' => 443, 'bool' => true]);

it('reads a blank api url as not set, calling the forge default like an absent one (strict config)', function (?string $unset): void {
    config()->set('git.providers.github.url', $unset);
    Http::fake(['https://api.github.com/user' => Http::response(['id' => 1, 'login' => 'octocat'])]);

    github()->user();

    Http::assertSent(fn ($request): bool => str_starts_with((string) $request->url(), 'https://api.github.com/user'));
})->with(['absent' => null, 'empty' => '', 'whitespace' => ' ']);

it('refuses a non-string cache store (strict config)', function (mixed $store): void {
    config()->set('git.cache.enabled', true);
    config()->set('git.cache.store', $store);
    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'octocat'], 200, ['ETag' => '"abc"'])]);

    expect(fn () => github()->user())
        ->toThrow(InvalidConfigurationException::class, 'git.cache.store');
})->with(['array' => [['array']], 'int' => 1]);

it('reads a blank cache store as not set, using the default store (strict config)', function (string $blank): void {
    config()->set('git.cache.enabled', true);
    config()->set('git.cache.store', $blank);
    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'octocat'], 200, ['ETag' => '"abc"'])]);

    expect(github()->user())->not->toBeNull();
})->with(['empty' => '', 'whitespace' => ' ']);

it('refuses a non-string logging channel (strict config)', function (mixed $channel): void {
    config()->set('git.logging.enabled', true);
    config()->set('git.logging.channel', $channel);
    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'octocat'])]);

    expect(fn () => github()->user())
        ->toThrow(InvalidConfigurationException::class, 'git.logging.channel');
})->with(['array' => [['stack']], 'int' => 1]);

it('reads a blank logging channel as not set, using the default channel (strict config)', function (string $blank): void {
    config()->set('git.logging.enabled', true);
    config()->set('git.logging.channel', $blank);
    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'octocat'])]);

    expect(github()->user())->not->toBeNull();
})->with(['empty' => '', 'whitespace' => ' ']);

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
})->with(['unset' => null, 'blank' => '', 'whitespace' => '  ']);

it('reads a blank token as not configured (strict config)', function (?string $unset): void {
    config()->set('git.providers.github.token', $unset);
    config()->set('git.providers.github.app.id', null);

    expect(Git::credentials('github'))->toBeNull();
})->with(['unset' => null, 'blank' => '', 'whitespace' => '  ']);
