<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\Input\NewBranch;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Dto\Input\NewFile;
use RoundlyConsulting\Git\Dto\Input\NewPullRequest;
use RoundlyConsulting\Git\Dto\Input\NewRelease;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Input\NewTag;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Input\UpdatedFile;

it('accepts valid input dtos', function () {
    expect(new NewRepository('acme'))->name->toBe('acme')
        ->and(new NewBranch('f', 'main'))->fromRef->toBe('main')
        ->and(new NewFile('a.txt', 'x', 'm', 'main'))->path->toBe('a.txt')
        ->and(new UpdatedFile('a.txt', 'x', 'm', 'main', 'sha'))->sha->toBe('sha')
        ->and(new NewPullRequest('t', 'h', 'b'))->title->toBe('t')
        ->and(new NewComment(1, 'hi'))->body->toBe('hi')
        ->and(new NewRelease('v1'))->tagName->toBe('v1')
        ->and(new NewTag('v1', 'ref'))->ref->toBe('ref')
        ->and(new NewWebhook('https://hook'))->events->toBe(['push']);
});

it('rejects invalid input dtos', function (Closure $make) {
    $make();
})->throws(InvalidArgumentException::class)->with([
    'empty repo name' => [fn () => new NewRepository(' ')],
    'empty branch name' => [fn () => new NewBranch('', 'main')],
    'empty base ref' => [fn () => new NewBranch('f', ' ')],
    'empty file path' => [fn () => new NewFile('', 'x', 'm', 'b')],
    'empty commit message' => [fn () => new NewFile('a', 'x', ' ', 'b')],
    'empty branch on file' => [fn () => new NewFile('a', 'x', 'm', '')],
    'empty updated path' => [fn () => new UpdatedFile('', 'x', 'm', 'b', 's')],
    'empty updated message' => [fn () => new UpdatedFile('a', 'x', ' ', 'b', 's')],
    'empty updated branch' => [fn () => new UpdatedFile('a', 'x', 'm', '', 's')],
    'empty updated sha' => [fn () => new UpdatedFile('a', 'x', 'm', 'b', '')],
    'empty pr title' => [fn () => new NewPullRequest(' ', 'h', 'b')],
    'empty pr head' => [fn () => new NewPullRequest('t', '', 'b')],
    'empty pr base' => [fn () => new NewPullRequest('t', 'h', '')],
    'empty comment' => [fn () => new NewComment(1, ' ')],
    'empty release tag' => [fn () => new NewRelease(' ')],
    'empty tag name' => [fn () => new NewTag('', 'ref')],
    'empty tag ref' => [fn () => new NewTag('v1', '')],
    'empty webhook url' => [fn () => new NewWebhook(' ')],
    'no webhook events' => [fn () => new NewWebhook('https://hook', [])],
]);
