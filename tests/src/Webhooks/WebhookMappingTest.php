<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
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

it('leaves the owner id of a real gitlab hook empty, as the hook carries no namespace id', function (string $fixture, string $owner) {
    // A real hook's project has no `namespace_id`; the owner id used to be a made-up "0".
    $repository = (new WebhookEvent(ProviderName::Gitlab, 'Hook', webhookFixture('gitlab', $fixture)))->repository();

    expect($repository?->owner->id)->toBe('')
        ->and($repository?->owner->name)->toBe($owner);
})->with([
    'merge request hook' => ['merge_request_opened', 'gitlabhq'],
    'push hook' => ['push_branch', 'mike'],
    'tag push hook' => ['tag_push', 'jsmith'],
]);

it('keeps the namespace id a gitlab hook does carry as the owner id', function () {
    $payload = webhookFixture('gitlab', 'merge_request_opened');
    $payload['project']['namespace_id'] = 42;

    expect((new WebhookEvent(ProviderName::Gitlab, 'Merge Request Hook', $payload))->repository()?->owner->id)->toBe('42');
});

$gitlabHook = fn (string $kind, array $fields): array => [
    'object_kind' => $kind,
    'project' => webhookFixture('gitlab', 'merge_request_opened')['project'],
    ...$fields,
];

it('dates the last activity of a gitlab hook repository at the hook\'s own event time', function (array $payload, string $expected) {
    Carbon::setTestNow('2026-10-07 12:00:00');

    $repository = (new WebhookEvent(ProviderName::Gitlab, 'Hook', $payload))->repository();

    expect($repository?->lastActivityAt->toIso8601ZuluString())->toBe(Carbon::parse($expected)->toIso8601ZuluString())
        ->and($repository?->createdAt->toIso8601ZuluString())->toBe('2026-10-07T12:00:00Z');
})->with([
    'merge request (updated_at)' => [fn () => webhookFixture('gitlab', 'merge_request_opened'), '2013-12-03T17:23:34Z'],
    'edited merge request (updated_at, not created_at)' => [function () {
        $payload = webhookFixture('gitlab', 'merge_request_opened');
        $payload['object_attributes']['updated_at'] = '2013-12-04T09:00:00Z';

        return $payload;
    }, '2013-12-04T09:00:00Z'],
    'push (the newest commit)' => [fn () => webhookFixture('gitlab', 'push_branch'), '2012-01-03T23:36:29+02:00'],
    'push listing the newest commit first' => [function () {
        $payload = webhookFixture('gitlab', 'push_branch');
        $payload['commits'] = array_reverse($payload['commits']);

        return $payload;
    }, '2012-01-03T23:36:29+02:00'],
    'issue' => [fn () => $gitlabHook('issue', ['object_attributes' => ['created_at' => '2013-12-03T17:15:43.000Z', 'updated_at' => '2013-12-03T17:16:00.000Z']]), '2013-12-03T17:16:00.000Z'],
    'comment' => [fn () => $gitlabHook('note', ['object_attributes' => ['created_at' => '2015-05-17T18:08:09.000Z', 'updated_at' => '2015-05-17T18:08:09.000Z']]), '2015-05-17T18:08:09.000Z'],
    'finished pipeline' => [fn () => $gitlabHook('pipeline', ['object_attributes' => ['created_at' => '2016-08-12T15:23:28.000Z', 'finished_at' => '2016-08-12T15:26:29.000Z']]), '2016-08-12T15:26:29.000Z'],
    'pending pipeline' => [fn () => $gitlabHook('pipeline', ['object_attributes' => ['created_at' => '2016-08-12T15:23:28.000Z', 'finished_at' => null]]), '2016-08-12T15:23:28.000Z'],
    'created job' => [fn () => $gitlabHook('build', ['build_created_at' => '2021-02-23T02:41:37.886Z', 'build_started_at' => null, 'build_finished_at' => null]), '2021-02-23T02:41:37.886Z'],
    'finished job' => [fn () => $gitlabHook('build', ['build_created_at' => '2021-02-23T02:41:37.886Z', 'build_started_at' => '2021-02-23T02:42:00.000Z', 'build_finished_at' => '2021-02-23T02:45:10.000Z']), '2021-02-23T02:45:10.000Z'],
    'deployment' => [fn () => $gitlabHook('deployment', ['status_changed_at' => '2021-04-28T21:50:00.000+02:00']), '2021-04-28T21:50:00.000+02:00'],
    'created release' => [fn () => $gitlabHook('release', ['action' => 'create', 'created_at' => '2020-11-02T12:55:12.000Z', 'released_at' => '2020-11-02T12:55:12.000Z']), '2020-11-02T12:55:12.000Z'],
]);

it('dates a gitlab hook repository at the time of mapping when the hook carries no event time', function (array $payload) {
    // Hooks carry no project `created_at`, and these carry no time of their own either.
    Carbon::setTestNow('2026-10-07 12:00:00');

    $repository = (new WebhookEvent(ProviderName::Gitlab, 'Hook', $payload))->repository();

    expect($repository?->lastActivityAt->toIso8601ZuluString())->toBe('2026-10-07T12:00:00Z')
        ->and($repository?->createdAt->toIso8601ZuluString())->toBe('2026-10-07T12:00:00Z');
})->with([
    'tag push (no commits)' => [fn () => webhookFixture('gitlab', 'tag_push')],
    'updated release (created_at is not the event)' => [fn () => $gitlabHook('release', ['action' => 'update', 'created_at' => '2020-11-02T12:55:12.000Z'])],
    'feature flag' => [fn () => $gitlabHook('feature_flag', ['object_attributes' => ['id' => 6, 'active' => true]])],
    'unreadable time' => [fn () => $gitlabHook('merge_request', ['object_attributes' => ['updated_at' => 'not a date']])],
]);

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
