<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Enums\ResourceState;
use RoundlyConsulting\Git\Mapping\BitbucketMapper;

beforeEach(function () {
    $this->mapper = new BitbucketMapper;
});

it('maps a rest commit with nested user author', function () {
    $raw = snapshotData('bitbucket/commit');

    $commit = $this->mapper->commit($raw);

    expect($commit->sha)->toBe($raw['hash'])
        ->and($commit->author->name)->toBe('Brodie Rao')
        ->and($commit->author->email)->toBe('a@b.c')
        ->and($commit->raw())->toBe($raw);
});

it('tolerates a webhook-shaped commit without a nested user', function () {
    $commit = $this->mapper->commit([
        'hash' => 'abc',
        'message' => 'fix',
        'author' => ['raw' => 'Jane Doe <jane@example.com>'],
        'date' => '2020-01-01T00:00:00+00:00',
    ]);

    expect($commit->author->name)->toBe('Jane Doe')
        ->and($commit->author->email)->toBe('jane@example.com');
});

it('normalizes pull request state', function () {
    $pr = $this->mapper->pullRequest([
        'id' => 5, 'title' => 'PR', 'state' => 'DECLINED',
        'source' => ['branch' => ['name' => 'f']], 'destination' => ['branch' => ['name' => 'm']],
        'author' => ['display_name' => 'Jane', 'links' => ['avatar' => ['href' => 'a']]],
        'links' => ['html' => ['href' => 'u']],
        'created_on' => '2020-01-01T00:00:00+00:00',
    ]);

    expect($pr->state)->toBe(ResourceState::Closed)
        ->and($pr->number)->toBe(5)
        ->and($pr->author?->name)->toBe('Jane');
});

it('maps a repository, issue, release and tag', function () {
    $repo = $this->mapper->repository(snapshotData('bitbucket/repository'));

    $issue = $this->mapper->issue([
        'id' => 3, 'title' => 'bug', 'state' => 'OPEN',
        'content' => ['raw' => 'broken'],
        'reporter' => ['display_name' => 'Jane', 'links' => ['avatar' => ['href' => 'a']]],
        'links' => ['html' => ['href' => 'u']],
        'created_on' => '2020-01-01T00:00:00+00:00',
    ]);

    $release = $this->mapper->release([
        'name' => 'v1', 'message' => 'notes', 'date' => '2020-01-01T00:00:00+00:00',
        'links' => ['html' => ['href' => 'u']],
    ]);

    $tag = $this->mapper->tag([
        'name' => 'v1', 'target' => ['hash' => 'abc'], 'links' => ['html' => ['href' => 'u']],
    ]);

    expect($repo->name)->toBe('Hello-World')
        ->and($issue->number)->toBe(3)
        ->and($issue->body)->toBe('broken')
        ->and($release->tagName)->toBe('v1')
        ->and($tag->sha)->toBe('abc');
});
