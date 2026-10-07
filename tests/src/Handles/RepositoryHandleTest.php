<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Comparison;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Dto\FileContent;
use RoundlyConsulting\Git\Dto\Input\NewBranch;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Dto\Input\NewFile;
use RoundlyConsulting\Git\Dto\Input\NewPullRequest;
use RoundlyConsulting\Git\Dto\Input\NewRelease;
use RoundlyConsulting\Git\Dto\Input\NewTag;
use RoundlyConsulting\Git\Dto\Input\UpdatedFile;
use RoundlyConsulting\Git\Dto\Issue;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ResourceState;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\Handles\PullRequestHandle;
use RoundlyConsulting\Git\Handles\RepositoryHandle;
use RoundlyConsulting\Git\Query\CommitQuery;
use RoundlyConsulting\Git\Webhooks\Webhooks;

function handleRepository(ProviderName $provider = ProviderName::Github, string $path = 'acme/app'): Repository
{
    return new Repository(
        provider: $provider,
        id: '1',
        path: $path,
        name: 'app',
        description: null,
        defaultBranch: 'main',
        owner: new Owner(id: '1', name: 'acme', avatar: null),
        createdAt: Carbon::now(),
        lastActivityAt: Carbon::now(),
    );
}

it('fills the path into every repository read', function (): void {
    $fake = Git::fake();
    $commit = new Commit(ProviderName::Github, 'abc', 'msg', new Author('n', 'e', null), null, Carbon::now());

    $fake->github()
        ->seedRepository(handleRepository())
        ->seedCommit($commit)
        ->seedBranches(['main'])
        ->seedIssue(new Issue(ProviderName::Github, '1', 5, 'Bug', null, ResourceState::Open, null, null, Carbon::now()))
        ->seedRelease(new Release(ProviderName::Github, '1', 'v1.0.0', 'v1', null, false, false, null, Carbon::now()))
        ->seedContents(new FileContent(path: 'README.md', content: '# app', sha: 's', size: 5, url: null))
        ->seedLanguages(['PHP' => 100]);

    $repo = Git::github()->repo('acme/app');

    expect($repo)->toBeInstanceOf(RepositoryHandle::class)
        ->and($repo->path())->toBe('acme/app')
        ->and($repo->get()->path)->toBe('acme/app')
        ->and($repo->branches(10))->toBeInstanceOf(Page::class)
        ->and($repo->commit('abc')->sha)->toBe('abc')
        ->and($repo->commits())->toBeInstanceOf(CommitQuery::class)
        ->and($repo->pullRequests('closed', 5))->toBeInstanceOf(Page::class)
        ->and($repo->issues('all', 5))->toBeInstanceOf(Page::class)
        ->and($repo->issue(5)->number)->toBe(5)
        ->and($repo->tags(5))->toBeInstanceOf(Page::class)
        ->and($repo->releases(5))->toBeInstanceOf(Page::class)
        ->and($repo->release('v1.0.0')->tagName)->toBe('v1.0.0')
        ->and($repo->contents('README.md', 'main')->content)->toBe('# app')
        ->and($repo->compare('main', 'feature/x'))->toBeInstanceOf(Comparison::class)
        ->and($repo->contributors(5))->toBeInstanceOf(Page::class)
        ->and($repo->languages())->toBe(['PHP' => 100])
        ->and($repo->webhooks())->toBeInstanceOf(Webhooks::class)
        ->and($repo->pullRequest(12))->toBeInstanceOf(PullRequestHandle::class);

    $fake->assertSent(ProviderName::Github, 'repository', fn (string $path): bool => $path === 'acme/app');
    $fake->assertSent(ProviderName::Github, 'branches', fn (string $path, int $perPage): bool => $path === 'acme/app' && $perPage === 10);
    $fake->assertSent(ProviderName::Github, 'commit', fn (string $path, string $sha): bool => $sha === 'abc');
    $fake->assertSent(ProviderName::Github, 'commits', fn (string $path): bool => $path === 'acme/app');
    $fake->assertSent(ProviderName::Github, 'pullRequests', fn (string $path, string $state, int $perPage): bool => $state === 'closed' && $perPage === 5);
    $fake->assertSent(ProviderName::Github, 'issues', fn (string $path, string $state): bool => $state === 'all');
    $fake->assertSent(ProviderName::Github, 'issue', fn (string $path, int $number): bool => $number === 5);
    $fake->assertSent(ProviderName::Github, 'tags');
    $fake->assertSent(ProviderName::Github, 'releases');
    $fake->assertSent(ProviderName::Github, 'release', fn (string $path, string $tag): bool => $tag === 'v1.0.0');
    $fake->assertSent(ProviderName::Github, 'contents', fn (string $path, string $file, ?string $ref): bool => $file === 'README.md' && $ref === 'main');
    $fake->assertSent(ProviderName::Github, 'compare', fn (string $path, string $base, string $head): bool => $base === 'main' && $head === 'feature/x');
    $fake->assertSent(ProviderName::Github, 'contributors');
    $fake->assertSent(ProviderName::Github, 'languages');
});

it('fills the path into every repository write', function (): void {
    fakeCredentials();

    $fake = Git::fake();
    $repo = Git::github()->repo('acme/app');

    expect($repo->createBranch(new NewBranch('feature/x', 'abc')))->toBe('refs/heads/feature/x')
        ->and($repo->createPullRequest(new NewPullRequest('Add CI', 'feature/x', 'main')))->toBeInstanceOf(PullRequest::class)
        ->and($repo->comment(new NewComment(12, 'Looks good')))->toBeInstanceOf(Comment::class)
        ->and($repo->createTag(new NewTag('v1.0.0', 'abc')))->toBeInstanceOf(Tag::class)
        ->and($repo->createRelease(new NewRelease('v1.0.0')))->toBeInstanceOf(Release::class)
        ->and($repo->createFile(new NewFile('docs/a.md', 'a', 'add a', 'main')))->toBeInstanceOf(Commit::class)
        ->and($repo->updateFile(new UpdatedFile('docs/a.md', 'b', 'edit a', 'main', 'sha')))->toBeInstanceOf(Commit::class)
        ->and($repo->cloneUrl('jane', Token::from('secret')))->toBe('https://token:secret@fake/acme/app.git');

    foreach (['createBranch', 'createPullRequest', 'comment', 'createTag', 'createRelease', 'createFile', 'updateFile', 'cloneUrlForRepository'] as $method) {
        $fake->assertSent(ProviderName::Github, $method, fn (string $path): bool => $path === 'acme/app');
    }
});

it('reaches the real endpoint through the handle', function (): void {
    Http::fake(['*/repos/acme/app/languages' => Http::response(['PHP' => 1200, 'Blade' => 300])]);

    expect(github()->repo('acme/app')->languages())->toBe(['PHP' => 1200, 'Blade' => 300]);

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/repos/acme/app/languages'));
});

it('opens a nested gitlab project path', function (): void {
    Git::fake();

    expect(Git::gitlab()->repo('group/sub/project')->path())->toBe('group/sub/project');
});

it('refuses a repository that belongs to another provider', function (): void {
    Git::fake();

    Git::github()->repo(handleRepository(ProviderName::Gitlab, 'group/project'));
})->throws(OutOfScopeException::class, 'belongs to GitLab, not to this GitHub provider');

it('refuses a repository path that could step outside the repository', function (string $path): void {
    Git::fake();

    expect(fn () => Git::github()->repo($path))->toThrow(OutOfScopeException::class);
})->with([
    'empty' => '',
    'parent segment' => 'acme/app/../other',
    'leading parent' => '../orgs/acme',
    'current segment' => 'acme/./app',
    'empty segment' => 'acme//app',
    'leading slash' => '/acme/app',
    'trailing slash' => 'acme/app/',
    'query' => 'acme/app?per_page=100',
    'fragment' => 'acme/app#x',
    'backslash' => 'acme\\app',
    'whitespace' => 'acme/my app',
]);

it('refuses a file path that could step outside the repository', function (): void {
    $fake = Git::fake();
    $repo = Git::github()->repo('acme/app');

    expect(fn () => $repo->contents('../../other/secret'))->toThrow(OutOfScopeException::class)
        ->and(fn () => $repo->createFile(new NewFile('docs/../../x', 'a', 'm', 'main')))->toThrow(OutOfScopeException::class)
        ->and(fn () => $repo->updateFile(new UpdatedFile('docs/..?/secret', 'a', 'm', 'main', 's')))->toThrow(OutOfScopeException::class);

    $fake->assertNothingSent();
});

it('refuses refs that could step outside the repository', function (): void {
    $fake = Git::fake();
    $repo = Git::github()->repo('acme/app');

    expect(fn () => $repo->commit('../../pulls'))->toThrow(OutOfScopeException::class)
        ->and(fn () => $repo->release('v1 beta'))->toThrow(OutOfScopeException::class)
        ->and(fn () => $repo->compare('main', '../x'))->toThrow(OutOfScopeException::class)
        ->and(fn () => $repo->compare('', 'main'))->toThrow(OutOfScopeException::class);

    $fake->assertNothingSent();
});

it('fails the webhook command cleanly on a path outside the repository', function (): void {
    fakeCredentials();

    Git::fake();

    $this->artisan('git:webhook github acme/app/../other --list')
        ->expectsOutputToContain('is not a repository path')
        ->assertExitCode(1);
});

it('fails the commits command cleanly on a path outside the repository', function (): void {
    fakeCredentials();

    Git::fake();

    $this->artisan('git:commits github acme/../x')
        ->expectsOutputToContain('is not a repository path')
        ->assertExitCode(1);
});
