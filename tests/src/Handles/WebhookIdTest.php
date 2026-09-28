<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Facades\Git;

/*
 * A webhook id is interpolated into a DELETE URL. Unchecked, `1/../../../../../repos/victim/prod`
 * collapses into GitHub's delete-repository endpoint — so the id is validated for the shape
 * each forge issues (numeric on GitHub/GitLab, a `{uuid}` on Bitbucket) and encoded.
 */

const BITBUCKET_HOOK = '{0b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b}';

it('refuses a webhook id that would escape the hooks collection', function (string $provider, string $id): void {
    Http::fake();

    expect(fn () => $provider()->repo('acme/app')->webhooks()->delete($id))->toThrow(OutOfScopeException::class, 'webhook id');

    Http::assertNothingSent();
})->with([
    'github traversal' => ['github', '1/../../../../../repos/victim/prod'],
    'github encoded' => ['github', '1%2F..%2F..%2Fvictim'],
    'github non-numeric' => ['github', 'abc'],
    'github empty' => ['github', ''],
    'gitlab traversal' => ['gitlab', '1/../../../victim%2Fprod'],
    'gitlab negative' => ['gitlab', '-1'],
    'bitbucket traversal' => ['bitbucket', '{x}/../../../victim'],
    'bitbucket bare word' => ['bitbucket', 'hook'],
]);

it('guards the flat deleteWebhook too', function (string $provider, string $id): void {
    Http::fake();

    expect(fn () => $provider()->deleteWebhook('acme/app', $id))->toThrow(OutOfScopeException::class);

    Http::assertNothingSent();
})->with([
    ['github', '1/../../../../../repos/victim/prod'],
    ['gitlab', '1/../../../victim%2Fprod'],
    ['bitbucket', '../victim'],
]);

it('deletes a well-formed id at the hooks endpoint only', function (): void {
    Http::fake(['*' => Http::response(null, 204)]);

    github()->repo('acme/app')->webhooks()->delete('11');
    gitlab()->repo('group/app')->webhooks()->delete('12');
    bitbucket()->repo('ws/app')->webhooks()->delete(BITBUCKET_HOOK);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/repos/acme/app/hooks/11'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/api/v4/projects/group%2Fapp/hooks/12'));
    // The braces are encoded: a raw `{uuid}` is a URI-template expression to Laravel's
    // client, which would expand it to nothing and DELETE the collection URL instead.
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/2.0/repositories/ws/app/hooks/%7B0b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b%7D'));
});

it('refuses a malformed id under the fake as well', function (): void {
    $fake = Git::fake();

    expect(fn () => Git::github()->repo('acme/app')->webhooks()->delete('1/../x'))->toThrow(OutOfScopeException::class);

    $fake->assertNotSent(ProviderName::Github, 'deleteWebhook');
});

it('reports a malformed --delete id instead of sending it', function (): void {
    Http::fake();
    config(['git.providers.github.token' => 'ghp_test']);

    $this->artisan('git:webhook github acme/app --delete=1/../../../../../repos/victim/prod')
        ->expectsOutputToContain('webhook id')
        ->assertFailed();

    Http::assertNothingSent();
});
