<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\WebhookEvent;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ResourceState;
use RoundlyConsulting\Git\Webhooks\Mapping\BitbucketWebhookMapper;
use RoundlyConsulting\Git\Webhooks\Mapping\GithubWebhookMapper;
use RoundlyConsulting\Git\Webhooks\Mapping\GitlabWebhookMapper;

function webhookFixture(string $provider, string $event): array
{
    return json_decode(
        (string) file_get_contents(__DIR__."/../../fixtures/webhooks/{$provider}/{$event}.json"),
        true,
    );
}

it('maps a github push into canonical commits', function () {
    $event = new WebhookEvent(ProviderName::Github, 'push', webhookFixture('github', 'push'));

    $commits = $event->commits();

    expect($commits)->toHaveCount(1)
        ->and($commits[0]->sha)->toBe('abc123')
        ->and($commits[0]->author->name)->toBe('Monalisa Octocat')
        ->and($event->ref())->toBe('refs/heads/main')
        ->and($event->repository()?->path)->toBe('octocat/Hello-World')
        ->and($event->pusher()?->name)->toBe('octocat')
        ->and($event->raw())->toBe(webhookFixture('github', 'push'));
});

it('maps a github pull_request into a canonical pull request', function () {
    $event = new WebhookEvent(ProviderName::Github, 'pull_request', webhookFixture('github', 'pull_request'));

    expect($event->pullRequest()?->number)->toBe(7)
        ->and($event->pullRequest()?->state)->toBe(ResourceState::Open)
        ->and($event->commits())->toBe([]);
});

it('maps a gitlab push and merge request', function () {
    $push = new WebhookEvent(ProviderName::Gitlab, 'Push Hook', webhookFixture('gitlab', 'push'));
    $mr = new WebhookEvent(ProviderName::Gitlab, 'Merge Request Hook', webhookFixture('gitlab', 'merge_request'));

    expect($push->commits()[0]->sha)->toBe('def456')
        ->and($push->ref())->toBe('refs/heads/main')
        ->and($push->pusher()?->name)->toBe('Jane')
        ->and($push->repository()?->path)->toBe('acme/web')
        ->and($mr->pullRequest()?->state)->toBe(ResourceState::Merged)
        ->and($mr->repository()?->path)->toBe('acme/web');
});

it('maps a bitbucket push and pull request', function () {
    $push = new WebhookEvent(ProviderName::Bitbucket, 'repo:push', webhookFixture('bitbucket', 'push'));
    $pr = new WebhookEvent(ProviderName::Bitbucket, 'pullrequest:created', webhookFixture('bitbucket', 'pull_request'));

    expect($push->commits()[0]->sha)->toBe('f7591a13')
        ->and($push->commits()[0]->author->name)->toBe('Brodie Rao')
        ->and($push->ref())->toBe('main')
        ->and($push->pusher()?->name)->toBe('Brodie Rao')
        ->and($pr->pullRequest()?->number)->toBe(12)
        ->and($pr->pullRequest()?->state)->toBe(ResourceState::Open);
});

it('returns null accessors for a missing resource', function () {
    $push = new WebhookEvent(ProviderName::Github, 'push', webhookFixture('github', 'push'));

    expect($push->pullRequest())->toBeNull();
});

it('reports the provider on each webhook mapper', function () {
    expect(resolve(GithubWebhookMapper::class)->provider())->toBe(ProviderName::Github)
        ->and(resolve(GitlabWebhookMapper::class)->provider())->toBe(ProviderName::Gitlab)
        ->and(resolve(BitbucketWebhookMapper::class)->provider())->toBe(ProviderName::Bitbucket);
});

it('returns empty or null for payloads without the nested resource', function () {
    $github = new WebhookEvent(ProviderName::Github, 'push', []);
    $gitlab = new WebhookEvent(ProviderName::Gitlab, 'Push Hook', []);
    $bitbucket = new WebhookEvent(ProviderName::Bitbucket, 'repo:push', []);

    expect($github->commits())->toBe([])
        ->and($github->repository())->toBeNull()
        ->and($github->pusher())->toBeNull()
        ->and($gitlab->commits())->toBe([])
        ->and($gitlab->pullRequest())->toBeNull()
        ->and($gitlab->repository())->toBeNull()
        ->and($gitlab->pusher())->toBeNull()
        ->and($gitlab->ref())->toBeNull()
        ->and($bitbucket->commits())->toBe([])
        ->and($bitbucket->pullRequest())->toBeNull()
        ->and($bitbucket->repository())->toBeNull()
        ->and($bitbucket->ref())->toBeNull()
        ->and($bitbucket->pusher())->toBeNull();
});
