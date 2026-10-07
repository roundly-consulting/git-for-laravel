<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\WebhookEvent;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ResourceState;
use RoundlyConsulting\Git\Webhooks\Mapping\BitbucketWebhookMapper;
use RoundlyConsulting\Git\Webhooks\Mapping\GithubWebhookMapper;
use RoundlyConsulting\Git\Webhooks\Mapping\GitlabWebhookMapper;

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

it('maps the url and the author of a real gitlab merge request hook', function () {
    // GitLab's hook carries `url` (not the REST `web_url`) and an `author_id` rather than an
    // author object; the top-level `user` is whoever triggered the event.
    $mr = new WebhookEvent(ProviderName::Gitlab, 'Merge Request Hook', webhookFixture('gitlab', 'merge_request_opened'));

    expect($mr->pullRequest()?->url)->toBe('https://gitlab.example.com/gitlabhq/gitlab-test/-/merge_requests/1')
        ->and($mr->pullRequest()?->author?->name)->toBe('jane')
        ->and($mr->pullRequest()?->author?->avatar)->toStartWith('https://www.gravatar.com/avatar/')
        ->and($mr->pullRequest()?->number)->toBe(1);
});

it('maps the repository of a real gitlab hook, whose project namespace is a string', function () {
    // A real hook's `project.namespace` is the namespace's display NAME, not the REST
    // object — it used to be read as `namespace.id` and threw a TypeError.
    $mr = new WebhookEvent(ProviderName::Gitlab, 'Merge Request Hook', webhookFixture('gitlab', 'merge_request_opened'));

    expect($mr->repository()?->path)->toBe('gitlabhq/gitlab-test')
        ->and($mr->repository()?->name)->toBe('gitlab-test')
        ->and($mr->repository()?->id)->toBe('14')
        ->and($mr->repository()?->defaultBranch)->toBe('master')
        ->and($mr->repository()?->owner->name)->toBe('gitlabhq')
        ->and($mr->repository()?->owner->raw)->toMatchArray(['name' => 'GitlabHQ']);
});

it('names no author when someone other than the author triggered the hook', function () {
    $payload = webhookFixture('gitlab', 'merge_request_opened');
    $payload['user'] = ['id' => 1, 'name' => 'Administrator', 'username' => 'root', 'avatar_url' => null, 'email' => '[REDACTED]'];

    $mr = new WebhookEvent(ProviderName::Gitlab, 'Merge Request Hook', $payload);

    expect($mr->pullRequest()?->author)->toBeNull()
        ->and($mr->pullRequest()?->url)->toBe('https://gitlab.example.com/gitlabhq/gitlab-test/-/merge_requests/1');
});

it('maps a bitbucket push and pull request', function () {
    $push = new WebhookEvent(ProviderName::Bitbucket, 'repo:push', webhookFixture('bitbucket', 'push'));
    $pr = new WebhookEvent(ProviderName::Bitbucket, 'pullrequest:created', webhookFixture('bitbucket', 'pull_request'));

    expect($push->commits()[0]->sha)->toBe('f7591a13')
        ->and($push->commits()[0]->author->name)->toBe('Brodie Rao')
        ->and($push->ref())->toBe('refs/heads/main')
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

it('qualifies a bitbucket ref the way github and gitlab send it', function (array $change, ?string $ref) {
    $event = new WebhookEvent(ProviderName::Bitbucket, 'repo:push', ['push' => ['changes' => [$change]]]);

    expect($event->ref())->toBe($ref);
})->with([
    'branch' => [['new' => ['type' => 'branch', 'name' => 'release/1.0']], 'refs/heads/release/1.0'],
    'tag' => [['new' => ['type' => 'tag', 'name' => 'v1.0.0']], 'refs/tags/v1.0.0'],
    'deleted branch' => [['new' => null, 'old' => ['type' => 'branch', 'name' => 'gone']], 'refs/heads/gone'],
    'unknown type' => [['new' => ['type' => 'bookmark', 'name' => 'x']], 'x'],
]);
