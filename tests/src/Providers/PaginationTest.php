<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Every forge caps `per_page` at 100 and answers a larger request with 100. Deciding
 * "is there more?" from `count >= perPage` then stops after page 1 — silently truncating —
 * and `perPage(0)` makes an empty page look full forever.
 */

function githubRepo(int $id): array
{
    return [
        'id' => $id, 'name' => "r{$id}", 'full_name' => "o/r{$id}", 'default_branch' => 'main',
        'owner' => ['id' => 1, 'login' => 'o'], 'created_at' => '2020-01-01T00:00:00Z', 'updated_at' => '2020-01-01T00:00:00Z',
    ];
}

it('walks every page when more than the forge cap was asked for', function (): void {
    Http::fakeSequence('*/user/repos*')
        ->push(array_map(githubRepo(...), range(1, 100)), 200, ['Link' => '<https://api.github.com/user/repos?page=2&per_page=100>; rel="next", <https://api.github.com/user/repos?page=2&per_page=100>; rel="last"'])
        ->push(array_map(githubRepo(...), range(101, 150)), 200, ['Link' => '<https://api.github.com/user/repos?page=1&per_page=100>; rel="prev", <https://api.github.com/user/repos?page=1&per_page=100>; rel="first"']);

    expect(github()->allRepositories(150)->count())->toBe(150);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'per_page=100'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'per_page=150'));
});

it('reports more pages from the Link header even when the page looks short', function (): void {
    Http::fake(['*/user/repos*' => Http::response(
        array_map(githubRepo(...), range(1, 100)),
        200,
        ['Link' => '<https://api.github.com/user/repos?page=2>; rel="next"'],
    )]);

    $page = github()->repositories(150);

    expect($page->hasMore)->toBeTrue()
        ->and($page->perPage)->toBe(100);
});

it('stops at the last page the Link header describes, even when it is full', function (): void {
    Http::fake(['*/user/repos*' => Http::response(
        array_map(githubRepo(...), range(1, 2)),
        200,
        ['Link' => '<https://api.github.com/user/repos?page=1>; rel="first", <https://api.github.com/user/repos?page=1>; rel="prev"'],
    )]);

    expect(github()->repositories(2)->hasMore)->toBeFalse();
});

it('refuses a page size below one instead of looping forever', function (int $perPage): void {
    Http::fake();

    expect(fn () => github()->repositories($perPage))->toThrow(InvalidArgumentException::class, 'per page')
        ->and(fn () => github()->repo('o/r')->commits()->perPage($perPage))->toThrow(InvalidArgumentException::class, 'per page');

    Http::assertNothingSent();
})->with([0, -5]);

it('caps the page size on every forge', function (string $provider, string $parameter): void {
    Http::fake(['*' => Http::response([])]);

    $provider()->repo('acme/app')->commits()->perPage(500)->get();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "{$parameter}=100"));
})->with([
    ['github', 'per_page'],
    ['gitlab', 'per_page'],
    ['bitbucket', 'pagelen'],
]);
