<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Comparison;
use RoundlyConsulting\Git\Dto\Contributor;
use RoundlyConsulting\Git\Dto\FileContent;
use RoundlyConsulting\Git\Dto\Input\NewBranch;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Dto\Input\NewFile;
use RoundlyConsulting\Git\Dto\Input\NewPullRequest;
use RoundlyConsulting\Git\Dto\Input\NewRelease;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Input\NewTag;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Input\UpdatedFile;
use RoundlyConsulting\Git\Dto\Issue;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Enums\CommentTarget;
use RoundlyConsulting\Git\Enums\ResourceState;

it('lists merge requests as pull requests', function () {
    Http::fake(['*/merge_requests*' => Http::response([[
        'id' => 1, 'iid' => 4, 'title' => 'MR', 'description' => 'd', 'state' => 'opened',
        'source_branch' => 'feature', 'target_branch' => 'main',
        'author' => ['username' => 'jane'], 'web_url' => 'u', 'created_at' => '2020-01-01T00:00:00Z',
    ]])]);

    expect(gitlab()->pullRequests('g/p')->first())
        ->toBeInstanceOf(PullRequest::class)->number->toBe(4)->state->toBe(ResourceState::Open);
});

it('gets a single merge request', function () {
    Http::fake(['*/merge_requests/4' => Http::response([
        'id' => 1, 'iid' => 4, 'title' => 'MR', 'state' => 'opened',
        'source_branch' => 'f', 'target_branch' => 'm', 'created_at' => '2020-01-01T00:00:00Z',
    ])]);

    expect(gitlab()->pullRequest('g/p', 4))->number->toBe(4);
});

it('lists and gets issues', function () {
    Http::fake([
        '*/issues/2' => Http::response(['id' => 9, 'iid' => 2, 'title' => 'bug', 'state' => 'opened', 'created_at' => '2020-01-01T00:00:00Z']),
        '*/issues*' => Http::response([['id' => 9, 'iid' => 2, 'title' => 'bug', 'state' => 'opened', 'author' => ['username' => 'jane'], 'created_at' => '2020-01-01T00:00:00Z']]),
    ]);

    expect(gitlab()->issues('g/p')->first())->toBeInstanceOf(Issue::class)->number->toBe(2)
        ->and(gitlab()->issue('g/p', 2))->title->toBe('bug');
});

it('lists tags, releases and a single release', function () {
    Http::fake([
        '*/repository/tags*' => Http::response([['name' => 'v1', 'commit' => ['id' => 'abc']]]),
        '*/releases/v1' => Http::response(['tag_name' => 'v1', 'name' => 'One', 'description' => 'n', 'created_at' => '2020-01-01T00:00:00Z']),
        '*/releases*' => Http::response([['tag_name' => 'v1', 'name' => 'One']]),
    ]);

    expect(gitlab()->tags('g/p')->first())->toBeInstanceOf(Tag::class)->name->toBe('v1')
        ->and(gitlab()->releases('g/p')->first())->toBeInstanceOf(Release::class)->tagName->toBe('v1')
        ->and(gitlab()->release('g/p', 'v1'))->name->toBe('One');
});

it('fetches decoded file contents', function () {
    Http::fake(['*/repository/files/*' => Http::response([
        'file_path' => 'README.md', 'content' => base64_encode('hi'), 'blob_id' => 'b', 'size' => 2,
    ])]);

    expect(gitlab()->contents('g/p', 'README.md'))->toBeInstanceOf(FileContent::class)->content->toBe('hi');
});

it('compares two refs', function () {
    Http::fake(['*/repository/compare*' => Http::response([
        'commits' => [['id' => 'a'], ['id' => 'b']],
        'diffs' => [
            ['new_path' => 'a.php', 'new_file' => true],
            ['new_path' => 'b.php', 'deleted_file' => true],
            ['new_path' => 'c.php'],
        ],
    ])]);

    $comparison = gitlab()->compare('g/p', 'a', 'b');

    expect($comparison)->toBeInstanceOf(Comparison::class)->aheadBy->toBe(2)
        ->and($comparison->files[0]->status)->toBe('added')
        ->and($comparison->files[1]->status)->toBe('removed')
        ->and($comparison->files[2]->status)->toBe('modified');
});

it('lists contributors and languages', function () {
    Http::fake([
        '*/repository/contributors*' => Http::response([['name' => 'Jane', 'email' => 'j@e.x', 'commits' => 10]]),
        '*/languages' => Http::response(['PHP' => 80.5, 'JS' => 19.5]),
    ]);

    expect(gitlab()->contributors('g/p')->first())->toBeInstanceOf(Contributor::class)->contributions->toBe(10)
        ->and(gitlab()->languages('g/p'))->toBe(['PHP' => 81, 'JS' => 20]);
});

it('searches repositories', function () {
    Http::fake(['*/api/v4/projects*' => Http::response([[
        'id' => 4, 'path_with_namespace' => 'g/p', 'path' => 'p', 'description' => null, 'default_branch' => 'main',
        'namespace' => ['id' => 2, 'path' => 'g'], 'created_at' => '2020-01-01T00:00:00Z', 'last_activity_at' => '2020-01-02T00:00:00Z',
    ]])]);

    expect(gitlab()->searchRepositories('p')->first())->path->toBe('g/p');
});

it('creates a repository, branch, files and a merge request', function () {
    Http::fake([
        '*/api/v4/projects' => Http::response([
            'id' => 4, 'path_with_namespace' => 'g/acme', 'path' => 'acme', 'default_branch' => 'main',
            'namespace' => ['id' => 2, 'path' => 'g'], 'created_at' => '2020-01-01T00:00:00Z', 'last_activity_at' => null,
        ]),
        '*/repository/branches' => Http::response(['name' => 'feature']),
        '*/repository/commits' => Http::response([
            'id' => 'c1', 'short_id' => 'c1', 'title' => 'add', 'message' => 'add',
            'author_name' => 'Jane', 'author_email' => 'jane@example.com',
            'authored_date' => '2020-01-01T00:00:00Z', 'web_url' => 'https://gitlab.com/g/p/-/commit/c1',
        ]),
        '*/merge_requests' => Http::response([
            'id' => 1, 'iid' => 4, 'title' => 'MR', 'state' => 'opened',
            'source_branch' => 'feature', 'target_branch' => 'main', 'created_at' => '2020-01-01T00:00:00Z',
        ]),
    ]);

    expect(gitlab()->createRepository(new NewRepository('acme', true))->name)->toBe('acme')
        // The contract (RepositoryHandle::createBranch) returns the new REF, as GitHub and the fake do.
        ->and(gitlab()->createBranch('g/p', new NewBranch('feature', 'main')))->toBe('refs/heads/feature')
        ->and(gitlab()->createFile('g/p', new NewFile('a.txt', 'x', 'add', 'main'))->sha)->toBe('c1')
        ->and(gitlab()->updateFile('g/p', new UpdatedFile('a.txt', 'y', 'edit', 'main', 'old'))->sha)->toBe('c1')
        ->and(gitlab()->createPullRequest('g/p', new NewPullRequest('MR', 'feature', 'main'))->number)->toBe(4);
});

it('comments, creates releases, tags and webhooks', function () {
    Http::fake([
        '*/notes' => Http::response(['id' => 11, 'body' => 'nice', 'author' => ['username' => 'jane'], 'created_at' => '2020-01-01T00:00:00Z']),
        '*/releases' => Http::response(['tag_name' => 'v2', 'name' => 'Two', 'description' => 'n']),
        '*/repository/tags' => Http::response(['name' => 'v2', 'commit' => ['id' => 'tagsha']]),
        '*/hooks/9' => Http::response([], 204),
        '*/hooks' => Http::response(['id' => 9, 'url' => 'https://hook', 'enable_ssl_verification' => true]),
    ]);

    expect(gitlab()->comment('g/p', new NewComment(4, 'nice', CommentTarget::PullRequest)))->toBeInstanceOf(Comment::class)->body->toBe('nice')
        ->and(gitlab()->createRelease('g/p', new NewRelease('v2', 'Two'))->tagName)->toBe('v2')
        ->and(gitlab()->createTag('g/p', new NewTag('v2', 'main'))->sha)->toBe('tagsha')
        ->and(gitlab()->createWebhook('g/p', new NewWebhook('https://hook', ['push'], 'secret'))->id)->toBe('9');

    gitlab()->deleteWebhook('g/p', '9');

    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/hooks/9'));
});

it('follows the X-Next-Page header when auto-paginating', function () {
    $repo = [
        'id' => 4, 'path_with_namespace' => 'g/p', 'path' => 'p', 'default_branch' => 'main',
        'namespace' => ['id' => 2, 'path' => 'g'], 'created_at' => '2020-01-01T00:00:00Z', 'last_activity_at' => null,
    ];

    Http::fakeSequence('*/api/v4/projects*')
        ->push([$repo], 200, ['X-Next-Page' => '2', 'X-Page' => '1'])
        ->push([$repo], 200, ['X-Next-Page' => '', 'X-Page' => '2']);

    expect(gitlab()->allRepositories(perPage: 1)->count())->toBe(2);
});

it('reads a file from the project default branch when no ref is given', function () {
    Http::fake(['*/repository/files/*' => Http::response([
        'file_path' => 'README.md', 'content' => base64_encode('# hi'), 'blob_id' => 'b', 'size' => 4,
    ])]);

    expect(gitlab()->repo('g/p')->contents('README.md')->content)->toBe('# hi');
    gitlab()->batch()->contents('g/p', ['README.md']);

    // `HEAD` is GitLab's own name for the default branch — `main` 404s on a `master` project.
    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request): bool => ! str_contains($request->url(), 'ref=HEAD'));
});

it('reads a file at the ref it is given', function () {
    Http::fake(['*/repository/files/*' => Http::response(['file_path' => 'README.md', 'content' => ''])]);

    gitlab()->contents('g/p', 'README.md', 'develop');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'ref=develop'));
});

it('returns the commit a file write made, not the file path', function (string $method, string $action) {
    $sha = str_repeat('d', 40);

    Http::fake(['*/repository/commits' => Http::response([
        'id' => $sha, 'short_id' => 'ddddddd', 'title' => 'docs', 'message' => 'docs',
        'author_name' => 'Jane', 'author_email' => 'jane@example.com', 'authored_date' => '2020-01-01T00:00:00Z',
    ])]);

    $commit = $method === 'create'
        ? gitlab()->repo('g/p')->createFile(new NewFile('docs/a.md', "bin\x00ary", 'docs', 'main'))
        : gitlab()->repo('g/p')->updateFile(new UpdatedFile('docs/a.md', "bin\x00ary", 'docs', 'main', 'blob'));

    expect($commit->sha)->toBe($sha)
        ->and($commit->author->name)->toBe('Jane');

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/v4/projects/g%2Fp/repository/commits')
        && $request['branch'] === 'main'
        && $request['commit_message'] === 'docs'
        && $request['actions'] === [[
            'action' => $action,
            'file_path' => 'docs/a.md',
            'content' => base64_encode("bin\x00ary"),
            'encoding' => 'base64',
        ]]);
})->with([
    ['create', 'create'],
    ['update', 'update'],
]);
