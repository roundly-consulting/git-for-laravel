<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\LazyCollection;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Page;

beforeEach(function () {
    Http::fake(['*' => Http::response([snapshotData('github/commit')])]);
});

it('applies every commit filter to the query string', function () {
    github()->commits('o/r')
        ->branch('main')
        ->author('octocat')
        ->path('src/')
        ->since('2020-01-01T00:00:00+00:00')
        ->until('2020-02-01T00:00:00+00:00')
        ->perPage(50)
        ->get();

    Http::assertSent(function ($request): bool {
        $url = $request->url();

        return str_contains($url, 'sha=main')
            && str_contains($url, 'author=octocat')
            && str_contains($url, 'path=src')
            && str_contains($url, 'since=2020-01-01')
            && str_contains($url, 'until=2020-02-01')
            && str_contains($url, 'per_page=50');
    });
});

it('accepts carbon instances for since and until', function () {
    github()->commits('o/r')->since(now()->subWeek())->until(now())->get();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'since=') && str_contains($request->url(), 'until='));
});

it('returns a page, a collection and the first item', function () {
    $query = github()->commits('o/r')->branch('main');

    expect($query->get())->toBeInstanceOf(Page::class)
        ->and($query->collect())->toHaveCount(1)
        ->and($query->first())->toBeInstanceOf(Commit::class);
});

it('lazily iterates a single page', function () {
    $lazy = github()->commits('o/r')->lazy();

    expect($lazy)->toBeInstanceOf(LazyCollection::class)
        ->and($lazy->count())->toBe(1);
});
