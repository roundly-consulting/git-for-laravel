<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use RoundlyConsulting\Git\Dto\WebhookEvent;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Events\PushReceived;

it('widens isPullRequest to bitbucket fulfilled and rejected', function () {
    expect((new WebhookEvent(ProviderName::Bitbucket, 'pullrequest:fulfilled', []))->isPullRequest())->toBeTrue()
        ->and((new WebhookEvent(ProviderName::Bitbucket, 'pullrequest:rejected', []))->isPullRequest())->toBeTrue()
        ->and((new WebhookEvent(ProviderName::Github, 'pull_request', []))->isPullRequest())->toBeTrue()
        ->and((new WebhookEvent(ProviderName::Github, 'push', []))->isPullRequest())->toBeFalse();
});

it('exposes canonical commits on the dispatched push event', function () {
    Event::fake();

    $body = (string) json_encode([
        'ref' => 'refs/heads/main',
        'commits' => [[
            'id' => 'sha1', 'message' => 'hi', 'timestamp' => '2020-01-01T00:00:00Z',
            'author' => ['name' => 'Jane', 'email' => 'jane@example.com'],
        ]],
    ]);
    $signature = 'sha256='.hash_hmac('sha256', $body, 'top-secret');

    $this->call('POST', '/git/webhooks/github', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X-Hub-Signature-256' => $signature,
        'HTTP_X-GitHub-Event' => 'push',
    ], $body)->assertOk();

    Event::assertDispatched(PushReceived::class, function (PushReceived $event): bool {
        return $event->commits()[0]->sha === 'sha1'
            && $event->ref() === 'refs/heads/main';
    });
});
