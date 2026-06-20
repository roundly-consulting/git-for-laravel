<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Enums\ResourceState;
use RoundlyConsulting\Git\Mapping\GithubMapper;

beforeEach(function () {
    $this->mapper = new GithubMapper;
});

it('maps a repository and keeps the raw payload', function () {
    $raw = snapshotData('github/repository');

    $repo = $this->mapper->repository($raw);

    expect($repo->path)->toBe('octocat/Hello-World')
        ->and($repo->owner->name)->toBe('octocat')
        ->and($repo->raw())->toBe($raw)
        ->and($repo->toArray())->not->toHaveKey('raw');
});

it('maps a commit from the rest shape', function () {
    $raw = snapshotData('github/commit');

    $commit = $this->mapper->commit($raw);

    expect($commit->sha)->toBe($raw['sha'])
        ->and($commit->author->name)->toBe('Monalisa Octocat')
        ->and($commit->raw())->toBe($raw);
});

it('normalizes pull request state and merged flag', function () {
    $open = $this->mapper->pullRequest([
        'id' => 1, 'number' => 1, 'title' => 't', 'state' => 'open',
        'head' => ['ref' => 'f'], 'base' => ['ref' => 'm'], 'created_at' => '2020-01-01T00:00:00Z',
        'draft' => true,
    ]);

    $merged = $this->mapper->pullRequest([
        'id' => 2, 'number' => 2, 'title' => 't', 'state' => 'closed', 'merged_at' => '2020-01-02T00:00:00Z',
        'head' => ['ref' => 'f'], 'base' => ['ref' => 'm'], 'created_at' => '2020-01-01T00:00:00Z',
    ]);

    expect($open->state)->toBe(ResourceState::Open)
        ->and($open->draft)->toBeTrue()
        ->and($merged->state)->toBe(ResourceState::Merged);
});

it('maps issues, releases and tags', function () {
    $issue = $this->mapper->issue([
        'id' => 1, 'number' => 3, 'title' => 'bug', 'state' => 'closed', 'created_at' => '2020-01-01T00:00:00Z',
    ]);
    $release = $this->mapper->release([
        'id' => 5, 'tag_name' => 'v1', 'name' => 'One', 'created_at' => '2020-01-01T00:00:00Z',
    ]);
    $tag = $this->mapper->tag(['name' => 'v1', 'commit' => ['sha' => 'abc', 'url' => 'u']]);

    expect($issue->state)->toBe(ResourceState::Closed)
        ->and($release->tagName)->toBe('v1')
        ->and($tag->sha)->toBe('abc');
});
