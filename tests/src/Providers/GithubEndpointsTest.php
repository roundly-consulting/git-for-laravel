<?php

declare(strict_types=1);

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
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
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Facades\Registry;

it('lists pull requests', function () {
    Http::fake(['*/repos/o/r/pulls*' => Http::response([[
        'id' => 1, 'number' => 7, 'title' => 'Add CI', 'body' => 'b', 'state' => 'open',
        'head' => ['ref' => 'feature'], 'base' => ['ref' => 'main'],
        'user' => ['login' => 'octocat', 'avatar_url' => 'a'],
        'html_url' => 'url', 'created_at' => '2020-01-01T00:00:00Z',
    ]])]);

    $page = github()->pullRequests('o/r');

    expect($page->first())->toBeInstanceOf(PullRequest::class)
        ->number->toBe(7)->sourceBranch->toBe('feature')->targetBranch->toBe('main');
});

it('gets a single pull request', function () {
    Http::fake(['*/repos/o/r/pulls/7' => Http::response([
        'id' => 1, 'number' => 7, 'title' => 't', 'state' => 'open',
        'head' => ['ref' => 'f'], 'base' => ['ref' => 'm'], 'created_at' => '2020-01-01T00:00:00Z',
    ])]);

    expect(github()->pullRequest('o/r', 7))->number->toBe(7);
});

it('lists and gets issues', function () {
    Http::fake([
        '*/repos/o/r/issues/3' => Http::response([
            'id' => 9, 'number' => 3, 'title' => 'bug', 'state' => 'open', 'created_at' => '2020-01-01T00:00:00Z',
        ]),
        '*/repos/o/r/issues*' => Http::response([[
            'id' => 9, 'number' => 3, 'title' => 'bug', 'state' => 'open',
            'user' => ['login' => 'octocat'], 'created_at' => '2020-01-01T00:00:00Z',
        ]]),
    ]);

    expect(github()->issues('o/r')->first())->toBeInstanceOf(Issue::class)->number->toBe(3)
        ->and(github()->issue('o/r', 3))->title->toBe('bug');
});

it('lists tags and releases', function () {
    Http::fake([
        '*/repos/o/r/tags*' => Http::response([['name' => 'v1.0', 'commit' => ['sha' => 'abc', 'url' => 'u']]]),
        '*/repos/o/r/releases/tags/v1.0' => Http::response([
            'id' => 5, 'tag_name' => 'v1.0', 'name' => 'One', 'body' => 'notes', 'draft' => false,
            'prerelease' => false, 'html_url' => 'u', 'created_at' => '2020-01-01T00:00:00Z',
        ]),
        '*/repos/o/r/releases*' => Http::response([[
            'id' => 5, 'tag_name' => 'v1.0', 'name' => 'One', 'draft' => false, 'prerelease' => false,
        ]]),
    ]);

    expect(github()->tags('o/r')->first())->toBeInstanceOf(Tag::class)->name->toBe('v1.0')
        ->and(github()->releases('o/r')->first())->toBeInstanceOf(Release::class)->tagName->toBe('v1.0')
        ->and(github()->release('o/r', 'v1.0'))->name->toBe('One');
});

it('fetches decoded file contents', function () {
    Http::fake(['*/repos/o/r/contents/README.md*' => Http::response([
        'path' => 'README.md', 'content' => base64_encode('hello'), 'sha' => 'abc', 'size' => 5, 'html_url' => 'u',
    ])]);

    expect(github()->contents('o/r', 'README.md', 'main'))
        ->toBeInstanceOf(FileContent::class)->content->toBe('hello')->sha->toBe('abc');
});

it('compares two refs', function () {
    Http::fake(['*/repos/o/r/compare/a...b' => Http::response([
        'ahead_by' => 2, 'behind_by' => 1,
        'files' => [['filename' => 'x.php', 'status' => 'modified', 'additions' => 3, 'deletions' => 1]],
    ])]);

    $comparison = github()->compare('o/r', 'a', 'b');

    expect($comparison)->toBeInstanceOf(Comparison::class)->aheadBy->toBe(2)
        ->and($comparison->files[0]->filename)->toBe('x.php');
});

it('lists contributors and languages', function () {
    Http::fake([
        '*/repos/o/r/contributors*' => Http::response([['id' => 1, 'login' => 'octocat', 'avatar_url' => 'a', 'contributions' => 42]]),
        '*/repos/o/r/languages' => Http::response(['PHP' => 100, 'Blade' => 20]),
    ]);

    expect(github()->contributors('o/r')->first())->toBeInstanceOf(Contributor::class)->contributions->toBe(42)
        ->and(github()->languages('o/r'))->toBe(['PHP' => 100, 'Blade' => 20]);
});

it('searches repositories', function () {
    Http::fake(['*/search/repositories*' => Http::response([
        'items' => [[
            'id' => 1, 'full_name' => 'o/r', 'name' => 'r', 'description' => null, 'default_branch' => 'main',
            'owner' => ['id' => 1, 'login' => 'o'], 'created_at' => '2020-01-01T00:00:00Z', 'pushed_at' => '2020-01-02T00:00:00Z',
        ]],
    ])]);

    expect(github()->searchRepositories('laravel')->first())->toBeInstanceOf(Repository::class)->name->toBe('r');
});

it('creates a repository', function () {
    Http::fake(['*/user/repos' => Http::response([
        'id' => 1, 'full_name' => 'o/acme', 'name' => 'acme', 'description' => 'd', 'default_branch' => 'main',
        'owner' => ['id' => 1, 'login' => 'o'], 'created_at' => '2020-01-01T00:00:00Z', 'pushed_at' => null,
    ])]);

    $repo = github()->createRepository(new NewRepository(name: 'acme', private: true, description: 'd'));

    expect($repo)->toBeInstanceOf(Repository::class)->name->toBe('acme');
    Http::assertSent(fn ($r): bool => $r->method() === 'POST' && $r['name'] === 'acme' && $r['private'] === true);
});

it('creates a branch from a base ref', function () {
    Http::fake([
        '*/repos/o/r/git/refs/heads/main' => Http::response(['object' => ['sha' => 'basesha']]),
        '*/repos/o/r/git/refs' => Http::response(['ref' => 'refs/heads/feature']),
    ]);

    expect(github()->createBranch('o/r', new NewBranch('feature', 'main')))->toBe('refs/heads/feature');
});

it('creates and updates a file', function () {
    Http::fake(['*/repos/o/r/contents/*' => Http::response([
        'commit' => ['sha' => 'c1', 'message' => 'add', 'author' => ['name' => 'n', 'email' => 'e', 'date' => '2020-01-01T00:00:00Z'], 'html_url' => 'u'],
    ])]);

    expect(github()->createFile('o/r', new NewFile('a.txt', 'x', 'add', 'main'))->sha)->toBe('c1')
        ->and(github()->updateFile('o/r', new UpdatedFile('a.txt', 'y', 'edit', 'main', 'old'))->sha)->toBe('c1');
});

it('creates a pull request', function () {
    Http::fake(['*/repos/o/r/pulls' => Http::response([
        'id' => 1, 'number' => 7, 'title' => 'Add CI', 'state' => 'open',
        'head' => ['ref' => 'feature'], 'base' => ['ref' => 'main'], 'created_at' => '2020-01-01T00:00:00Z',
    ])]);

    expect(github()->createPullRequest('o/r', new NewPullRequest('Add CI', 'feature', 'main', 'body')))
        ->number->toBe(7);
});

it('comments on an issue', function () {
    Http::fake(['*/repos/o/r/issues/7/comments' => Http::response([
        'id' => 11, 'body' => 'nice', 'user' => ['login' => 'octocat'], 'html_url' => 'u', 'created_at' => '2020-01-01T00:00:00Z',
    ])]);

    expect(github()->comment('o/r', new NewComment(7, 'nice')))->toBeInstanceOf(Comment::class)->body->toBe('nice');
});

it('creates a release and a tag', function () {
    Http::fake([
        '*/repos/o/r/releases' => Http::response(['id' => 5, 'tag_name' => 'v2', 'name' => 'Two', 'draft' => false, 'prerelease' => false]),
        '*/repos/o/r/git/refs' => Http::response(['object' => ['sha' => 'tagsha'], 'url' => 'u']),
    ]);

    expect(github()->createRelease('o/r', new NewRelease('v2', 'Two'))->tagName)->toBe('v2')
        ->and(github()->createTag('o/r', new NewTag('v2', 'tagsha'))->sha)->toBe('tagsha');
});

it('creates and deletes a webhook', function () {
    Http::fake([
        '*/repos/o/r/hooks/9' => Http::response([], 204),
        '*/repos/o/r/hooks' => Http::response(['id' => 9, 'config' => ['url' => 'https://hook'], 'events' => ['push'], 'active' => true]),
    ]);

    expect(github()->createWebhook('o/r', new NewWebhook('https://hook', ['push'], 'secret')))
        ->toBeInstanceOf(Webhook::class)->id->toBe('9');

    github()->deleteWebhook('o/r', '9');

    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/hooks/9'));
});

it('guards writes behind authentication', function () {
    Registry::provider(ProviderName::Github)
        ->createRepository(new NewRepository('x'));
})->throws(InvalidCredentialsException::class);

it('surfaces upstream 422 errors as http exceptions', function () {
    Http::fake(['*/user/repos' => Http::response(['message' => 'Validation Failed'], 422)]);

    github()->createRepository(new NewRepository('dup'));
})->throws(RequestException::class);

it('records rate limit status from response headers', function () {
    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'o'], 200, [
        'X-RateLimit-Limit' => '5000',
        'X-RateLimit-Remaining' => '4999',
        'X-RateLimit-Used' => '1',
        'X-RateLimit-Reset' => (string) Carbon::parse('2030-01-01')->timestamp,
    ])]);

    $github = github();
    $github->user();

    expect($github->rateLimit())->not->toBeNull()
        ->limit->toBe(5000)->remaining->toBe(4999)->used->toBe(1);
});
