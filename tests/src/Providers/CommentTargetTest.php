<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Enums\CommentTarget;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;

/*
 * GitHub numbers issues and pull requests in ONE sequence and serves comments on both from
 * `/issues/{n}/comments`. GitLab numbers issues and merge requests SEPARATELY — issue #3 and
 * merge request !3 are different objects — so on GitLab the comment has to say which.
 */

function gitlabNote(): array
{
    return ['id' => 11, 'body' => 'hi', 'author' => ['username' => 'jane'], 'created_at' => '2020-01-01T00:00:00Z'];
}

it('comments on a gitlab issue at the issue notes endpoint', function (): void {
    Http::fake(['*/issues/3/notes' => Http::response(gitlabNote())]);

    gitlab()->repo('g/p')->comment(new NewComment(3, 'hi', CommentTarget::Issue));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v4/projects/g%2Fp/issues/3/notes'));
});

it('comments on a gitlab merge request at the merge request notes endpoint', function (): void {
    Http::fake(['*/merge_requests/3/notes' => Http::response(gitlabNote())]);

    gitlab()->repo('g/p')->comment(new NewComment(3, 'hi', CommentTarget::PullRequest));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v4/projects/g%2Fp/merge_requests/3/notes'));
});

it('targets the merge request from a pull request handle', function (): void {
    Http::fake(['*/merge_requests/4/notes' => Http::response(gitlabNote())]);

    gitlab()->repo('g/p')->pullRequest(4)->comment('hi');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/merge_requests/4/notes'));
});

it('refuses to guess between a gitlab issue and merge request', function (): void {
    Http::fake();

    gitlab()->repo('g/p')->comment(new NewComment(3, 'hi'));
})->throws(InvalidArgumentException::class, 'CommentTarget');

it('keeps github on its shared issues endpoint for either target', function (?CommentTarget $target): void {
    Http::fake(['*/issues/7/comments' => Http::response([
        'id' => 1, 'body' => 'hi', 'user' => ['login' => 'o'], 'created_at' => '2020-01-01T00:00:00Z',
    ])]);

    github()->repo('o/r')->comment(new NewComment(7, 'hi', $target));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/repos/o/r/issues/7/comments'));
})->with([null, CommentTarget::Issue, CommentTarget::PullRequest]);

it('refuses an issue comment on bitbucket, which has no issues here', function (): void {
    Http::fake();

    bitbucket()->repo('ws/app')->comment(new NewComment(3, 'hi', CommentTarget::Issue));
})->throws(FeatureNotSupportedException::class);
