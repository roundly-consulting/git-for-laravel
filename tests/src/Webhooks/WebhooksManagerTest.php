<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\Providers\Github;

function githubAuthed(): Github
{
    return Git::github(Token::from('token-value'));
}

it('registers a hook with the derived url and configured secret', function () {
    Http::fake([
        '*/repos/acme/api/hooks*' => Http::sequence()
            ->push([])
            ->push(['id' => 7, 'config' => ['url' => route('git.webhooks', ['provider' => 'github'])], 'events' => ['push'], 'active' => true]),
    ]);

    $hook = githubAuthed()->repo('acme/api')->webhooks()->register();

    expect($hook->id)->toBe('7');

    Http::assertSent(function (Request $request): bool {
        if ($request->method() !== 'POST') {
            return true;
        }

        $data = $request->data();

        return ($data['config']['url'] ?? null) === route('git.webhooks', ['provider' => 'github'])
            && ($data['config']['secret'] ?? null) === 'top-secret';
    });
});

it('is idempotent and does not create a duplicate hook', function () {
    $url = route('git.webhooks', ['provider' => 'github']);

    Http::fake([
        '*/repos/acme/api/hooks*' => Http::response([
            ['id' => 7, 'config' => ['url' => $url], 'events' => ['push'], 'active' => true],
        ]),
    ]);

    $hook = githubAuthed()->repo('acme/api')->webhooks()->register();

    expect($hook->id)->toBe('7');

    Http::assertSentCount(1);
});

it('accepts an explicit url override', function () {
    Http::fake([
        '*/repos/acme/api/hooks*' => Http::sequence()
            ->push([])
            ->push(['id' => 9, 'config' => ['url' => 'https://override.test/hook'], 'events' => ['push'], 'active' => true]),
    ]);

    $hook = githubAuthed()->repo('acme/api')->webhooks()->register(url: 'https://override.test/hook');

    expect($hook->url)->toBe('https://override.test/hook');
});

it('probes and deletes hooks by url', function () {
    $url = 'https://app.test/hook';

    Http::fake([
        '*/repos/acme/api/hooks/7' => Http::response([], 204),
        '*/repos/acme/api/hooks*' => Http::response([
            ['id' => 7, 'config' => ['url' => $url], 'events' => ['push'], 'active' => true],
        ]),
    ]);

    $manager = githubAuthed()->repo('acme/api')->webhooks();

    expect($manager->registered($url))->toBeTrue()
        ->and($manager->registered('https://nope.test'))->toBeFalse()
        ->and($manager->deleteByUrl($url))->toBeTrue();
});

it('refuses to register the package route with no secret the route can verify', function () {
    config()->set('git.providers.github.webhook_secret', null);
    Http::fake();

    expect(fn () => githubAuthed()->repo('acme/api')->webhooks()->register())
        ->toThrow(InvalidArgumentException::class, 'git.providers.github.webhook_secret');

    Http::assertNothingSent();
});

it('refuses a secret the package route would not verify against', function (?string $configured) {
    // The route checks deliveries against the CONFIGURED secret only, so a hook signed with
    // any other one is answered 403 for every delivery.
    config()->set('git.providers.github.webhook_secret', $configured);
    Http::fake();

    expect(fn () => githubAuthed()->repo('acme/api')->webhooks()->register(secret: 'another-secret'))
        ->toThrow(InvalidArgumentException::class, 'git.providers.github.webhook_secret');

    Http::assertNothingSent();
})->with(['configured differently' => 'top-secret', 'not configured' => null]);

it('still registers a host-owned url with no secret', function () {
    config()->set('git.providers.github.webhook_secret', null);

    Http::fake([
        '*/repos/acme/api/hooks*' => Http::sequence()
            ->push([])
            ->push(['id' => 9, 'config' => ['url' => 'https://app.test/own'], 'events' => ['push'], 'active' => true], 201),
    ]);

    expect(githubAuthed()->repo('acme/api')->webhooks()->register(url: 'https://app.test/own')->id)->toBe('9');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && ! array_key_exists('secret', $request->data()['config']));
});

it('throws when deriving a url with webhooks disabled', function () {
    config()->set('git.webhooks.enabled', false);

    Http::fake(['*/repos/acme/api/hooks*' => Http::response([])]);

    expect(fn () => githubAuthed()->repo('acme/api')->webhooks()->register())
        ->toThrow(InvalidArgumentException::class);
});

it('guards against an unauthenticated provider', function () {
    Http::preventStrayRequests();

    expect(fn () => Git::github()->repo('acme/api')->webhooks()->all())
        ->toThrow(InvalidCredentialsException::class);
});

it('refuses to treat a hook with other events as the one being registered', function () {
    $url = 'https://app.test/hooks/github';

    Http::fake(['*/repos/acme/api/hooks*' => Http::response([
        ['type' => 'Repository', 'id' => 7, 'name' => 'web', 'active' => true, 'events' => ['push'],
            'config' => ['content_type' => 'json', 'insecure_ssl' => '0', 'url' => $url]],
    ])]);

    expect(fn () => githubAuthed()->repo('acme/api')->webhooks()->register(url: $url, events: ['push', 'pull_request']))
        ->toThrow(InvalidArgumentException::class, '[7]');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
});

it('stays idempotent for the same events in any order', function () {
    $url = 'https://app.test/hooks/github';

    Http::fake(['*/repos/acme/api/hooks*' => Http::response([
        ['type' => 'Repository', 'id' => 7, 'name' => 'web', 'active' => true, 'events' => ['pull_request', 'push'],
            'config' => ['content_type' => 'json', 'insecure_ssl' => '0', 'url' => $url]],
    ])]);

    expect(githubAuthed()->repo('acme/api')->webhooks()->register(url: $url, events: ['push', 'pull_request'])->id)->toBe('7');

    Http::assertSentCount(1);
});

it('compares gitlab and bitbucket events in the form the forge lists them', function (string $provider, array $listed, array $events, bool $matches) {
    $url = 'https://app.test/hooks/x';
    $hook = $provider === 'gitlab'
        ? array_fill_keys($listed, true) + ['id' => 3, 'url' => $url, 'alert_status' => 'executable', 'push_events' => false, 'merge_requests_events' => false, 'issues_events' => false]
        : ['type' => 'webhook_subscription', 'uuid' => '{0b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b}', 'url' => $url, 'active' => true, 'events' => $listed];

    Http::fake(['*/hooks*' => Http::response($provider === 'gitlab' ? [$hook] : ['values' => [$hook]])]);

    $register = fn () => Git::provider($provider, Token::from('t'))->repo('acme/api')->webhooks()->register(url: $url, events: $events);

    if ($matches) {
        expect($register()->url)->toBe($url);
    } else {
        expect($register)->toThrow(InvalidArgumentException::class, $url);
    }

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
})->with([
    'gitlab native flag' => ['gitlab', ['merge_requests_events'], ['merge_requests_events'], true],
    'gitlab canonical name' => ['gitlab', ['push_events', 'merge_requests_events'], ['pull_request', 'push'], true],
    'gitlab missing event' => ['gitlab', ['push_events'], ['push', 'issues'], false],
    'bitbucket full pull_request' => ['bitbucket', ['repo:push', 'pullrequest:created', 'pullrequest:updated', 'pullrequest:fulfilled', 'pullrequest:rejected'], ['pull_request', 'push'], true],
    'bitbucket native push' => ['bitbucket', ['repo:push'], ['repo:push'], true],
    'bitbucket created only' => ['bitbucket', ['pullrequest:created'], ['pull_request'], false],
]);

it('matches seeded hooks on the fake exactly as listed ones', function () {
    fakeCredentials();

    $fake = Git::fake();
    $fake->fakeFor(ProviderName::Bitbucket)->seedWebhooks([
        new Webhook(ProviderName::Bitbucket, '{0b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b}', 'https://app.test/a', ['pullrequest:created'], true),
        new Webhook(ProviderName::Bitbucket, '{1b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b}', 'https://app.test/b', ['push'], true),
    ]);

    $webhooks = Git::bitbucket()->repo('acme/api')->webhooks();

    expect(fn () => $webhooks->register(url: 'https://app.test/a', events: ['pull_request']))->toThrow(InvalidArgumentException::class)
        ->and($webhooks->register(url: 'https://app.test/b', events: ['repo:push'])->id)->toBe('{1b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b}');

    $fake->assertNotSent(ProviderName::Bitbucket, 'createWebhook');
});
