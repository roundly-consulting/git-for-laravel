<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Dto\Input\NewPullRequest;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;

it('lists pull requests', function () {
    Http::fake(['*/pullrequests*' => Http::response(['values' => [[
        'id' => 5, 'title' => 'PR', 'description' => 'd', 'state' => 'OPEN',
        'source' => ['branch' => ['name' => 'feature']], 'destination' => ['branch' => ['name' => 'main']],
        'author' => ['display_name' => 'John'],
        'links' => ['html' => ['href' => 'u']], 'created_on' => '2020-01-01T00:00:00Z',
    ]]])]);

    expect(bitbucket()->pullRequests('o/r')->first())
        ->toBeInstanceOf(PullRequest::class)->number->toBe(5)->sourceBranch->toBe('feature');
});

it('gets a single pull request', function () {
    Http::fake(['*/pullrequests/5' => Http::response([
        'id' => 5, 'title' => 'PR', 'state' => 'OPEN',
        'source' => ['branch' => ['name' => 'f']], 'destination' => ['branch' => ['name' => 'm']],
        'created_on' => '2020-01-01T00:00:00Z',
    ])]);

    expect(bitbucket()->pullRequest('o/r', 5))->number->toBe(5);
});

it('creates a repository, pull request, comment and webhook', function () {
    Http::fake([
        '*/2.0/repositories/o/acme' => Http::response([
            'uuid' => 'u1', 'full_name' => 'o/acme', 'description' => 'd', 'mainbranch' => ['name' => 'main'],
            'owner' => ['uuid' => 'ou', 'username' => 'o'], 'created_on' => '2020-01-01T00:00:00Z', 'updated_on' => null,
        ]),
        '*/pullrequests' => Http::response([
            'id' => 5, 'title' => 'PR', 'state' => 'OPEN',
            'source' => ['branch' => ['name' => 'f']], 'destination' => ['branch' => ['name' => 'm']],
            'created_on' => '2020-01-01T00:00:00Z',
        ]),
        '*/comments' => Http::response([
            'id' => 11, 'content' => ['raw' => 'nice'], 'user' => ['display_name' => 'John'],
            'links' => ['html' => ['href' => 'u']], 'created_on' => '2020-01-01T00:00:00Z',
        ]),
        '*/hooks/9' => Http::response([], 204),
        '*/hooks' => Http::response(['uuid' => '9', 'url' => 'https://hook', 'active' => true]),
    ]);

    expect(bitbucket()->createRepository(new NewRepository('o/acme', true))->name)->toBe('acme')
        ->and(bitbucket()->createPullRequest('o/r', new NewPullRequest('PR', 'f', 'm'))->number)->toBe(5)
        ->and(bitbucket()->comment('o/r', new NewComment(5, 'nice')))->toBeInstanceOf(Comment::class)
        ->and(bitbucket()->createWebhook('o/r', new NewWebhook('https://hook', ['push'], 'secret')))
        ->toBeInstanceOf(Webhook::class)->id->toBe('9');

    bitbucket()->deleteWebhook('o/r', '9');

    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE' && str_contains($r->url(), '/hooks/9'));
});

it('throws for unsupported read endpoints', function (string $method, array $args) {
    bitbucket()->{$method}(...$args);
})->throws(FeatureNotSupportedException::class)->with([
    'languages' => ['languages', ['o/r']],
    'compare' => ['compare', ['o/r', 'a', 'b']],
    'contributors' => ['contributors', ['o/r']],
    'releases' => ['releases', ['o/r']],
]);

it('follows the cursor next url when auto-paginating', function () {
    $repo = [
        'uuid' => 'u1', 'full_name' => 'o/r', 'description' => null, 'mainbranch' => ['name' => 'main'],
        'owner' => ['uuid' => 'ou', 'username' => 'o'], 'created_on' => '2020-01-01T00:00:00Z', 'updated_on' => null,
    ];

    Http::fakeSequence('*/2.0/repositories*')
        ->push(['values' => [$repo], 'next' => 'https://api.bitbucket.org/2.0/repositories?page=2'])
        ->push(['values' => [$repo]]);

    expect(bitbucket()->allRepositories(perPage: 1)->count())->toBe(2);
});
