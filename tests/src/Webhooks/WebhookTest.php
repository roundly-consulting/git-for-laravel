<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Git\Events\PullRequestEventReceived;
use RoundlyConsulting\Git\Events\PushReceived;
use RoundlyConsulting\Git\Events\WebhookReceived;

it('dispatches a push event for a correctly signed github payload', function () {
    Event::fake();

    $body = (string) json_encode(['ref' => 'refs/heads/main']);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'top-secret');

    $this->call('POST', '/git/webhooks/github', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Hub-Signature-256' => $signature,
        'HTTP_X-GitHub-Event' => 'push',
    ], $body)->assertOk();

    Event::assertDispatched(WebhookReceived::class);
    Event::assertDispatched(PushReceived::class);
});

it('dispatches a pull request event for github', function () {
    Event::fake();

    $body = (string) json_encode(['action' => 'opened']);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'top-secret');

    $this->call('POST', '/git/webhooks/github', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Hub-Signature-256' => $signature,
        'HTTP_X-GitHub-Event' => 'pull_request',
    ], $body)->assertOk();

    Event::assertDispatched(PullRequestEventReceived::class);
});

it('rejects a tampered github payload with 403 and dispatches nothing', function () {
    Event::fake([WebhookReceived::class, PushReceived::class]);

    $body = (string) json_encode(['ref' => 'refs/heads/main']);
    $signature = 'sha256='.hash_hmac('sha256', 'different body', 'top-secret');

    $this->call('POST', '/git/webhooks/github', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Hub-Signature-256' => $signature,
        'HTTP_X-GitHub-Event' => 'push',
    ], $body)->assertForbidden();

    Event::assertNotDispatched(WebhookReceived::class);
    Event::assertNotDispatched(PushReceived::class);
});

it('rejects a missing signature header', function () {
    Event::fake([WebhookReceived::class, PushReceived::class]);

    $this->postJson('/git/webhooks/github', ['ref' => 'refs/heads/main'])
        ->assertForbidden();

    Event::assertNotDispatched(WebhookReceived::class);
});

it('returns 404 for an unknown provider', function () {
    $this->postJson('/git/webhooks/unknown', [])->assertNotFound();
});

it('verifies gitlab token and bitbucket signature', function () {
    Event::fake();

    $this->call('POST', '/git/webhooks/gitlab', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Gitlab-Token' => 'top-secret',
        'HTTP_X-Gitlab-Event' => 'Push Hook',
    ], (string) json_encode(['ref' => 'main']))->assertOk();

    $body = (string) json_encode(['push' => true]);
    $this->call('POST', '/git/webhooks/bitbucket', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Hub-Signature' => 'sha256='.hash_hmac('sha256', $body, 'top-secret'),
        'HTTP_X-Event-Key' => 'repo:push',
    ], $body)->assertOk();

    Event::assertDispatched(PushReceived::class, 2);
});

it('rejects when no webhook secret is configured', function () {
    config()->set('git.providers.github.webhook_secret', null);

    $this->postJson('/git/webhooks/github', ['ref' => 'main'])->assertForbidden();
});
