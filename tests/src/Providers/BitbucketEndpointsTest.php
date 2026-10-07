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
use RoundlyConsulting\Git\Enums\ResourceState;
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

it('asks for every closed state when listing closed pull requests', function () {
    // Bitbucket reads a repeated `state` parameter as "any of these"; SUPERSEDED counts as
    // closed (ResourceState), so leaving it out drops pull requests the package calls closed.
    Http::fake(['*/pullrequests*' => Http::response(['pagelen' => 30, 'page' => 1, 'size' => 1, 'values' => [[
        'type' => 'pullrequest', 'id' => 9, 'title' => 'Old approach', 'description' => '', 'state' => 'SUPERSEDED',
        'source' => ['branch' => ['name' => 'old']], 'destination' => ['branch' => ['name' => 'main']],
        'author' => ['display_name' => 'John'], 'links' => ['html' => ['href' => 'https://bitbucket.org/o/r/pull-requests/9']],
        'created_on' => '2020-01-01T00:00:00.000000+00:00',
    ]]])]);

    expect(bitbucket()->pullRequests('o/r', 'closed')->first()?->state)->toBe(ResourceState::Closed);

    Http::assertSent(function ($request): bool {
        $query = (string) parse_url($request->url(), PHP_URL_QUERY);

        return str_contains($query, 'state=DECLINED&state=SUPERSEDED')
            && ! str_contains(rawurldecode($query), 'state[');
    });
});

it('keeps sending a single state for open and merged pull requests', function (string $state, string $wire) {
    Http::fake(['*/pullrequests*' => Http::response(['values' => []])]);

    bitbucket()->pullRequests('o/r', $state);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), "state={$wire}&")
        && substr_count($request->url(), 'state=') === 1);
})->with([['open', 'OPEN'], ['merged', 'MERGED'], ['all', 'ALL']]);

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
        '*/hooks/%7B*' => Http::response([], 204),
        '*/hooks' => Http::response(['uuid' => '{0b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b}', 'url' => 'https://hook', 'active' => true]),
    ]);

    expect(bitbucket()->createRepository(new NewRepository('o/acme', true))->name)->toBe('acme')
        ->and(bitbucket()->createPullRequest('o/r', new NewPullRequest('PR', 'f', 'm'))->number)->toBe(5)
        ->and(bitbucket()->comment('o/r', new NewComment(5, 'nice')))->toBeInstanceOf(Comment::class)
        ->and(bitbucket()->createWebhook('o/r', new NewWebhook('https://hook', ['push'], 'secret')))
        ->toBeInstanceOf(Webhook::class)->id->toBe('{0b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b}');

    bitbucket()->deleteWebhook('o/r', '{0b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b}');

    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE'
        && str_ends_with($r->url(), '/hooks/%7B0b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b%7D'));
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

it('refuses commit filters bitbucket cannot apply instead of dropping them', function (Closure $filter, string $named) {
    Http::fake();

    expect(fn () => $filter(bitbucket()->repo('acme/app')->commits()->branch('main'))->get())
        ->toThrow(FeatureNotSupportedException::class, "Provider [Bitbucket] cannot filter commits by [{$named}]");

    // Refused before any request: a silently unfiltered page is the bug.
    Http::assertNothingSent();
})->with([
    'author' => [fn ($query) => $query->author('octocat'), 'author'],
    'since' => [fn ($query) => $query->since('2024-01-01'), 'since'],
    'until' => [fn ($query) => $query->until('2024-02-01'), 'until'],
    'several' => [fn ($query) => $query->author('octocat')->since('2024-01-01'), 'author, since'],
]);

it('still sends the branch and path commit filters bitbucket supports', function () {
    Http::fake(['*/commits*' => Http::response(['values' => []])]);

    bitbucket()->repo('acme/app')->commits()->branch('main')->path('src/')->get();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/2.0/repositories/acme/app/commits')
        && $request['include'] === 'main'
        && $request['path'] === 'src/');
});
