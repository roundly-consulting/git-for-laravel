<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\WebhookEvent;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ResourceState;
use RoundlyConsulting\Git\Mapping\GitlabMapper;

beforeEach(function () {
    $this->mapper = new GitlabMapper;
});

it('maps a repository with namespace owner', function () {
    $raw = snapshotData('gitlab/repository');

    $repo = $this->mapper->repository($raw);

    expect($repo->path)->toBe($raw['path_with_namespace'])
        ->and($repo->raw())->toBe($raw);
});

it('coerces a nested release self link to null', function () {
    $release = $this->mapper->release([
        'tag_name' => 'v1', 'name' => 'One', '_links' => ['self' => ['href' => 'x']],
        'created_at' => '2020-01-01T00:00:00Z',
    ]);

    expect($release->url)->toBeNull()
        ->and($release->tagName)->toBe('v1');
});

it('normalizes merge request state', function () {
    $mr = $this->mapper->pullRequest([
        'id' => 1, 'iid' => 4, 'title' => 'MR', 'state' => 'merged',
        'source_branch' => 'f', 'target_branch' => 'm', 'created_at' => '2020-01-01T00:00:00Z',
    ]);

    expect($mr->state)->toBe(ResourceState::Merged)
        ->and($mr->number)->toBe(4);
});

it('maps a draft merge request as a draft', function (array $extra, bool $draft) {
    $mr = $this->mapper->pullRequest([
        'id' => 1, 'iid' => 2, 'title' => 'Draft: Rework auth', 'state' => 'opened',
        'source_branch' => 'auth', 'target_branch' => 'main', 'created_at' => '2026-01-01T00:00:00Z',
        'web_url' => 'https://gitlab.com/g/p/-/merge_requests/2',
    ] + $extra);

    expect($mr->draft)->toBe($draft);
})->with([
    'draft' => [['draft' => true, 'work_in_progress' => true], true],
    'ready' => [['draft' => false, 'work_in_progress' => false], false],
    'no draft key' => [[], false],
]);

it('maps a draft merge request hook as a draft', function () {
    $mr = new WebhookEvent(ProviderName::Gitlab, 'Merge Request Hook', webhookFixture('gitlab', 'merge_request_opened'));

    expect($mr->pullRequest()?->draft)->toBeTrue();
});
