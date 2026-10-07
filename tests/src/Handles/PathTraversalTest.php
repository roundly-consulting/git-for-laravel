<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Input\NewFile;
use RoundlyConsulting\Git\Dto\Input\UpdatedFile;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;

/*
 * A forge URL is built by interpolation, and the HTTP stack (libcurl, Guzzle) decodes and
 * collapses `%2e%2e` exactly as it does `..` — so a percent-encoded dot segment is a
 * traversal just like a literal one, and has to be refused before any request is built.
 */

dataset('encoded traversals', [
    'lowercase' => '%2e%2e/%2e%2e/%2e%2e/victim/private/contents/.env',
    'uppercase' => '%2E%2E/%2E%2E/victim/private',
    'mixed with literal dot' => '.%2e/x',
    'double encoded' => '%252e%252e/%252e%252e/victim',
    'encoded slash' => 'docs%2F..%2F..%2F..%2Fvictim',
    'encoded backslash' => 'docs%5C..%5Cvictim',
    'encoded null byte' => 'docs/a%00.md',
    // `?` and `#` are legal in a file name, but a dot segment a stack could cut off at
    // one of them (`..#x` read as `..` + a fragment) is still a traversal.
    'dot segment before an encoded query' => '..%3F/victim',
    'dot segment before an encoded fragment' => 'docs/..%23/../victim',
    'dot segment before a literal fragment' => '..#/victim',
]);

it('refuses a percent-encoded dot segment in a file read', function (string $path): void {
    Http::fake();

    expect(fn () => github()->repo('acme/app')->contents($path))->toThrow(OutOfScopeException::class);

    Http::assertNothingSent();
})->with('encoded traversals');

it('refuses a percent-encoded dot segment in a file write', function (string $path): void {
    Http::fake();

    $repo = github()->repo('acme/app');

    expect(fn () => $repo->createFile(new NewFile($path, 'x', 'msg', 'main')))->toThrow(OutOfScopeException::class)
        ->and(fn () => $repo->updateFile(new UpdatedFile($path, 'x', 'msg', 'main', 'sha')))->toThrow(OutOfScopeException::class);

    Http::assertNothingSent();
})->with('encoded traversals');

it('refuses a percent-encoded dot segment in a ref', function (): void {
    Http::fake();

    $repo = github()->repo('acme/app');

    expect(fn () => $repo->commit('%2e%2e/%2e%2e/%2e%2e/victim/private/commits/HEAD'))->toThrow(OutOfScopeException::class)
        ->and(fn () => $repo->release('%2e%2e/%2e%2e/x'))->toThrow(OutOfScopeException::class)
        ->and(fn () => $repo->compare('main', '%2e%2e/%2e%2e/x'))->toThrow(OutOfScopeException::class);

    Http::assertNothingSent();
});

it('refuses a percent-encoded dot segment in a repository path', function (string $provider): void {
    Http::fake();

    expect(fn () => $provider()->repo('acme/%2e%2e/victim/private'))->toThrow(OutOfScopeException::class);

    Http::assertNothingSent();
})->with(['github', 'gitlab', 'bitbucket']);

it('guards the flat driver methods, not only the handles', function (): void {
    Http::fake();

    $github = github();

    expect(fn () => $github->contents('acme/app', '%2e%2e/%2e%2e/victim/private/contents/.env'))->toThrow(OutOfScopeException::class)
        ->and(fn () => $github->repository('acme/../victim'))->toThrow(OutOfScopeException::class)
        ->and(fn () => $github->commit('acme/app', '../../victim'))->toThrow(OutOfScopeException::class)
        ->and(fn () => $github->createFile('acme/app', new NewFile('%2e%2e/pwn.txt', 'x', 'm', 'main')))->toThrow(OutOfScopeException::class)
        ->and(fn () => $github->batch()->contents('acme/app', ['%2e%2e/%2e%2e/x']))->toThrow(OutOfScopeException::class)
        ->and(fn () => $github->batch()->repositories(['acme/%2e%2e/victim']))->toThrow(OutOfScopeException::class)
        ->and(fn () => bitbucket()->commit('acme/app', '%2e%2e/%2e%2e/x'))->toThrow(OutOfScopeException::class)
        ->and(fn () => gitlab()->contents('acme/app', '../x'))->toThrow(OutOfScopeException::class);

    Http::assertNothingSent();
});

it('encodes every path segment so the stack cannot decode one into another', function (): void {
    Http::fake(['*' => Http::response(['path' => 'docs/100%.md', 'content' => base64_encode('x'), 'sha' => 's'])]);

    github()->repo('acme/app')->contents('docs/100%.md', ref: 'main');
    github()->repo('acme/app')->contents('docs/my file.md');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/repos/acme/app/contents/docs/100%25.md?ref=main'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/repos/acme/app/contents/docs/my%20file.md'));
});

it('keeps a ref with a slash addressable while encoding its segments', function (): void {
    Http::fake(['*' => Http::response([
        'sha' => 'abc', 'commit' => ['message' => 'm', 'author' => ['name' => 'n', 'email' => 'e', 'date' => '2020-01-01T00:00:00Z']],
    ])]);

    github()->repo('acme/app')->commit('release/1.0');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/repos/acme/app/commits/release/1.0'));
});

it('addresses a file or ref with # or ? in it, percent-encoded on every forge', function (string $provider, Closure $call, string $sent): void {
    Http::fake(['*' => Http::response([
        // Enough of each forge's answer for the mappers; the URL is what is under test.
        'path' => 'p', 'file_path' => 'p', 'content' => base64_encode('x'), 'sha' => 's', 'blob_id' => 'b',
        'id' => 'c1', 'hash' => 'c1', 'message' => 'm', 'author_name' => 'n', 'author_email' => 'e',
        'authored_date' => '2020-01-01T00:00:00Z', 'date' => '2020-01-01T00:00:00Z', 'author' => ['raw' => 'n <e>'],
        'commit' => ['message' => 'm', 'author' => ['name' => 'n', 'email' => 'e', 'date' => '2020-01-01T00:00:00Z']],
        'commits' => [], 'diffs' => [], 'files' => [],
    ])]);

    $call($provider()->repo('acme/app'));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), $sent));
})->with([
    'github file with #' => ['github', fn ($repo) => $repo->contents('docs/C#/intro.md'), '/repos/acme/app/contents/docs/C%23/intro.md'],
    'github file with ?' => ['github', fn ($repo) => $repo->contents('what?.md'), '/repos/acme/app/contents/what%3F.md'],
    'github ref with #' => ['github', fn ($repo) => $repo->compare('main', 'fix/#123'), '/repos/acme/app/compare/main...fix/%23123'],
    'gitlab file with #' => ['gitlab', fn ($repo) => $repo->contents('docs/C#/intro.md'), '/repository/files/docs%2FC%23%2Fintro.md'],
    'gitlab file with ?' => ['gitlab', fn ($repo) => $repo->contents('what?.md'), '/repository/files/what%3F.md'],
    'gitlab ref with #' => ['gitlab', fn ($repo) => $repo->commit('fix/#123'), '/repository/commits/fix%2F%23123'],
    'bitbucket ref with #' => ['bitbucket', fn ($repo) => $repo->commit('fix/#123'), '/repositories/acme/app/commit/fix/%23123'],
]);

it('still refuses # and ? where they cannot belong', function (): void {
    Http::fake();

    $repo = github()->repo('acme/app');

    expect(fn () => github()->repo('acme/app#x'))->toThrow(OutOfScopeException::class)
        ->and(fn () => github()->repo('acme/app?x=1'))->toThrow(OutOfScopeException::class)
        ->and(fn () => $repo->compare('main', 'fix?x'))->toThrow(OutOfScopeException::class)
        ->and(fn () => $repo->contents('docs\\intro.md'))->toThrow(OutOfScopeException::class)
        ->and(fn () => $repo->contents("docs/a\0.md"))->toThrow(OutOfScopeException::class)
        ->and(fn () => github()->installations()->forUser('jane#x'))->toThrow(OutOfScopeException::class);

    Http::assertNothingSent();
});
