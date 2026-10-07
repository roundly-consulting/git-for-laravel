<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Credentials\Token;
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
