<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;

/*
 * `webhooks()->register(events: ['push', 'pull_request'])` promises a hook the package's own
 * route can verify and that delivers the events asked for — on every forge, in its own words.
 */

it('sends the secret to bitbucket so the delivery is signed', function (): void {
    Http::fake(['*/hooks' => Http::response(['uuid' => '{0b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b}', 'url' => 'https://hook', 'active' => true])]);

    bitbucket()->createWebhook('ws/app', new NewWebhook('https://hook', ['push'], 's3cr3t'));

    Http::assertSent(fn (Request $request): bool => $request['secret'] === 's3cr3t');
});

it('omits the bitbucket secret when there is none', function (): void {
    Http::fake(['*/hooks' => Http::response(['uuid' => '{0b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b}', 'url' => 'https://hook'])]);

    bitbucket()->createWebhook('ws/app', new NewWebhook('https://hook'));

    Http::assertSent(fn (Request $request): bool => ! array_key_exists('secret', $request->data()));
});

it('subscribes bitbucket to every pull request transition', function (): void {
    Http::fake(['*/hooks' => Http::response(['uuid' => '{0b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b}', 'url' => 'https://hook'])]);

    bitbucket()->createWebhook('ws/app', new NewWebhook('https://hook', ['push', 'pull_request']));

    Http::assertSent(fn (Request $request): bool => $request['events'] === [
        'repo:push', 'pullrequest:created', 'pullrequest:updated', 'pullrequest:fulfilled', 'pullrequest:rejected',
    ]);
});

it('reads bitbucket hook events back in the canonical names', function (): void {
    Http::fake(['*/hooks' => Http::response(['values' => [[
        'uuid' => '{0b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b}', 'url' => 'https://hook', 'active' => true,
        'events' => ['repo:push', 'pullrequest:created', 'pullrequest:fulfilled', 'issue:created'],
    ]]])]);

    expect(bitbucket()->listWebhooks('ws/app')[0]->events)->toBe(['push', 'pull_request', 'issue:created']);
});

it('subscribes gitlab to merge request events for pull_request', function (): void {
    Http::fake(['*/hooks' => Http::response(['id' => 9, 'url' => 'https://hook', 'push_events' => true, 'merge_requests_events' => true])]);

    $hook = gitlab()->createWebhook('g/p', new NewWebhook('https://hook', ['push', 'pull_request'], 'tok'));

    Http::assertSent(fn (Request $request): bool => $request['push_events'] === true
        && $request['merge_requests_events'] === true
        && $request['token'] === 'tok');

    expect($hook->events)->toBe(['push', 'pull_request']);
});

it('does not subscribe gitlab to push when only pull requests were asked for', function (): void {
    Http::fake(['*/hooks' => Http::response(['id' => 9, 'url' => 'https://hook', 'merge_requests_events' => true])]);

    gitlab()->createWebhook('g/p', new NewWebhook('https://hook', ['pull_request']));

    Http::assertSent(fn (Request $request): bool => $request['push_events'] === false
        && $request['merge_requests_events'] === true);
});

it('passes a native gitlab event flag through', function (): void {
    Http::fake(['*/hooks' => Http::response(['id' => 9, 'url' => 'https://hook', 'pipeline_events' => true])]);

    gitlab()->createWebhook('g/p', new NewWebhook('https://hook', ['pipeline_events']));

    Http::assertSent(fn (Request $request): bool => $request['pipeline_events'] === true);
});

it('refuses a gitlab event it cannot express instead of dropping it', function (): void {
    Http::fake();

    gitlab()->createWebhook('g/p', new NewWebhook('https://hook', ['deployment_status']));
})->throws(InvalidArgumentException::class, 'deployment_status');

it('reads gitlab hook state from its alert status, not from ssl verification', function (): void {
    Http::fake(['*/hooks' => Http::response([
        ['id' => 3, 'url' => 'https://a', 'push_events' => true, 'merge_requests_events' => true, 'enable_ssl_verification' => false, 'alert_status' => 'executable'],
        ['id' => 4, 'url' => 'https://b', 'push_events' => true, 'enable_ssl_verification' => true, 'alert_status' => 'disabled'],
    ])]);

    [$live, $disabled] = gitlab()->listWebhooks('g/p');

    expect($live->active)->toBeTrue()
        ->and($live->events)->toBe(['push', 'pull_request'])
        ->and($disabled->active)->toBeFalse();
});

it('refuses to create an inactive gitlab hook it cannot create inactive', function (): void {
    Http::fake();

    gitlab()->createWebhook('g/p', new NewWebhook('https://hook', active: false));
})->throws(FeatureNotSupportedException::class);
