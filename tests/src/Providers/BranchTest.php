<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Branch;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\Tests\testable\BaseProvider;

/*
 * `branch()` resolves ONE branch head by its exact name. `commit($ref)` resolves any
 * commit-ish — a tag or a short sha would do — which is wrong for a caller that deploys by
 * branch name only.
 */

it('reads a github branch head from its exact ref', function (): void {
    Http::fake(['*/repos/octocat/Hello-World/git/ref/heads/featureA' => snapshot('github/git-ref')]);

    $branch = github()->repo('octocat/Hello-World')->branch('featureA');

    expect($branch)
        ->toBeInstanceOf(Branch::class)
        ->provider->toBe(ProviderName::Github)
        ->name->toBe('featureA')
        ->sha->toBe('aa218f56b14c9653891f9e74264a383fa43fefbd')
        ->and($branch->raw()['node_id'])->toBe('MDM6UmVmcmVmcy9oZWFkcy9mZWF0dXJlQQ==')
        ->and($branch->toArray())->not->toHaveKey('raw');
});

it('keeps a slashed github branch as path segments, each encoded', function (): void {
    Http::fake(['*' => Http::response([
        'ref' => 'refs/heads/fix/#123',
        'object' => ['type' => 'commit', 'sha' => str_repeat('a', 40)],
    ])]);

    expect(github()->branch('o/r', 'fix/#123')->sha)->toBe(str_repeat('a', 40));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && str_ends_with($request->url(), '/repos/o/r/git/ref/heads/fix/%23123'));
});

it('answers a missing github branch as the usual 404', function (): void {
    Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

    expect(fn () => github()->branch('o/r', 'gone'))->toThrow(RequestException::class);
});

it('refuses a github answer that is not exactly that branch head', function (array $answer): void {
    Http::fake(['*' => Http::response($answer)]);

    expect(fn () => github()->branch('o/r', 'main'))->toThrow(UnexpectedValueException::class, '[main]');
})->with([
    'another ref' => [['ref' => 'refs/heads/main-old', 'object' => ['type' => 'commit', 'sha' => str_repeat('a', 40)]]],
    'a tag object' => [['ref' => 'refs/heads/main', 'object' => ['type' => 'tag', 'sha' => str_repeat('a', 40)]]],
    'a short sha' => [['ref' => 'refs/heads/main', 'object' => ['type' => 'commit', 'sha' => 'abc123']]],
    'a list of refs' => [[['ref' => 'refs/heads/main', 'object' => ['type' => 'commit', 'sha' => str_repeat('a', 40)]]]],
]);

it('reads a gitlab branch with its name as one encoded segment', function (): void {
    Http::fake(['*' => Http::response(['name' => 'feature/x', 'commit' => ['id' => str_repeat('b', 40)]])]);

    $branch = gitlab()->repo('group/project')->branch('feature/x');

    expect($branch->provider)->toBe(ProviderName::Gitlab)
        ->and($branch->name)->toBe('feature/x')
        ->and($branch->sha)->toBe(str_repeat('b', 40))
        ->and($branch->raw()['name'])->toBe('feature/x');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v4/projects/group%2Fproject/repository/branches/feature%2Fx'));
});

it('reads a bitbucket branch from its refs endpoint', function (): void {
    Http::fake(['*' => Http::response(['name' => 'feature/x', 'target' => ['hash' => str_repeat('c', 40)]])]);

    $branch = bitbucket()->repo('ws/app')->branch('feature/x');

    expect($branch->provider)->toBe(ProviderName::Bitbucket)
        ->and($branch->sha)->toBe(str_repeat('c', 40));

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/2.0/repositories/ws/app/refs/branches/feature/x'));
});

it('refuses a gitlab or bitbucket answer without a commit', function (Closure $call, array $answer): void {
    Http::fake(['*' => Http::response($answer)]);

    expect($call)->toThrow(UnexpectedValueException::class);
})->with([
    'gitlab' => [fn () => gitlab()->branch('g/p', 'main'), ['name' => 'main']],
    'bitbucket' => [fn () => bitbucket()->branch('ws/app', 'main'), ['name' => 'main', 'target' => []]],
]);

it('refuses a traversal or whitespace branch name before any request', function (string $name): void {
    Http::fake();

    expect(fn () => github()->repo('o/r')->branch($name))->toThrow(OutOfScopeException::class)
        ->and(fn () => github()->branch('o/r', $name))->toThrow(OutOfScopeException::class);

    Http::assertNothingSent();
})->with(['../x', 'feature/../../x', 'a b', '%2e%2e/x', '']);

it('reports finding a branch on all three forges', function (): void {
    expect(github()->supports(Feature::FindBranch))->toBeTrue()
        ->and(gitlab()->supports(Feature::FindBranch))->toBeTrue()
        ->and(bitbucket()->supports(Feature::FindBranch))->toBeTrue()
        ->and(Feature::FindBranch->value)->toBe('branch');
});

it('refuses branch() on a driver that does not implement it', function (): void {
    expect(fn () => (new BaseProvider)->branch('o/r', 'main'))->toThrow(FeatureNotSupportedException::class);
});

it('answers a seeded branch from the fake by name, and names the seeder otherwise', function (): void {
    $fake = Git::fake();
    $fake->fakeFor(ProviderName::Github)
        ->seedBranch(new Branch(ProviderName::Github, 'main', str_repeat('d', 40)))
        ->seedBranch(new Branch(ProviderName::Github, 'develop', str_repeat('e', 40)));

    expect(Git::github()->repo('acme/app')->branch('develop')->sha)->toBe(str_repeat('e', 40))
        ->and(Git::github()->repo('acme/app')->branch('main')->sha)->toBe(str_repeat('d', 40))
        ->and(fn () => Git::github()->repo('acme/app')->branch('release'))->toThrow(RuntimeException::class, 'seedBranch()');

    $fake->assertSent(ProviderName::Github, 'branch', fn (string $path, string $name): bool => $path === 'acme/app' && $name === 'develop');
});
