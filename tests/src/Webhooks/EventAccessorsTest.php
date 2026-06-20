<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\WebhookEvent;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ResourceState;
use RoundlyConsulting\Git\Events\PullRequestEventReceived;
use RoundlyConsulting\Git\Events\PushReceived;

it('exposes push convenience accessors', function () {
    $event = new WebhookEvent(ProviderName::Github, 'push', webhookFixture('github', 'push'));
    $push = new PushReceived($event);

    expect($push->commits()[0]->sha)->toBe('abc123')
        ->and($push->ref())->toBe('refs/heads/main')
        ->and($push->repository()?->path)->toBe('octocat/Hello-World')
        ->and($push->pusher()?->name)->toBe('octocat');
});

it('exposes pull request convenience accessors', function () {
    $event = new WebhookEvent(ProviderName::Github, 'pull_request', webhookFixture('github', 'pull_request'));
    $pr = new PullRequestEventReceived($event);

    expect($pr->pullRequest()?->state)->toBe(ResourceState::Open)
        ->and($pr->action())->toBe('pull_request')
        ->and($pr->repository()?->path)->toBe('octocat/Hello-World');
});
