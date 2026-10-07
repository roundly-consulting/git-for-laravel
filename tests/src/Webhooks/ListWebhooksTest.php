<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Facades\Git;

it('lists gitlab webhooks', function () {
    Http::fake(['*/projects/*/hooks*' => Http::response([
        ['id' => 3, 'url' => 'https://app.test/hook', 'push_events' => true, 'enable_ssl_verification' => true],
    ])]);

    $hooks = Git::gitlab(Token::from('x'))->listWebhooks('acme/web');

    expect($hooks)->toHaveCount(1)
        ->and($hooks[0]->id)->toBe('3')
        ->and($hooks[0]->events)->toBe(['push']);
});

it('lists bitbucket webhooks', function () {
    Http::fake(['*/repositories/*/hooks*' => Http::response([
        'values' => [
            ['uuid' => '{1}', 'url' => 'https://app.test/hook', 'events' => ['repo:push'], 'active' => true],
        ],
    ])]);

    $hooks = Git::bitbucket(Token::from('x'))->listWebhooks('acme/api');

    expect($hooks)->toHaveCount(1)
        ->and($hooks[0]->id)->toBe('{1}');
});

/**
 * Hook pages as each forge serves them: GitHub ends a page with a `Link: rel="next"`,
 * GitLab with `X-Next-Page`, Bitbucket with a `next` URL in the envelope.
 *
 * @return array{0: Closure(list<array<string, mixed>>, bool): mixed, 1: Closure(string, int): array<string, mixed>, 2: string}
 */
function hookPagesFor(string $provider): array
{
    return match ($provider) {
        'github' => [
            fn (array $hooks, bool $more) => Http::response($hooks, 200, $more
                ? ['Link' => '<https://api.github.com/repositories/1/hooks?per_page=100&page=2>; rel="next", <https://api.github.com/repositories/1/hooks?per_page=100&page=2>; rel="last"']
                : []),
            fn (string $url, int $id): array => [
                'type' => 'Repository', 'id' => $id, 'name' => 'web', 'active' => true, 'events' => ['push'],
                'config' => ['content_type' => 'json', 'insecure_ssl' => '0', 'url' => $url],
            ],
            'acme/api',
        ],
        'gitlab' => [
            fn (array $hooks, bool $more) => Http::response($hooks, 200, $more
                ? ['X-Page' => '1', 'X-Next-Page' => '2', 'X-Per-Page' => '100']
                : ['X-Page' => '2', 'X-Next-Page' => '', 'X-Per-Page' => '100']),
            fn (string $url, int $id): array => [
                'id' => $id, 'url' => $url, 'project_id' => 3, 'push_events' => true,
                'merge_requests_events' => false, 'issues_events' => false,
                'enable_ssl_verification' => true, 'alert_status' => 'executable',
            ],
            'acme/api',
        ],
        'bitbucket' => [
            fn (array $hooks, bool $more) => Http::response(array_filter([
                'pagelen' => 10, 'page' => $more ? 1 : 2, 'size' => 11, 'values' => $hooks,
                'next' => $more ? 'https://api.bitbucket.org/2.0/repositories/acme/api/hooks?page=2' : null,
            ], fn ($value): bool => $value !== null)),
            fn (string $url, int $id): array => [
                'type' => 'webhook_subscription', 'uuid' => sprintf('{0b1e7a52-3c4d-4e5f-8a9b-%012d}', $id),
                'url' => $url, 'description' => 'hook', 'subject_type' => 'repository',
                'active' => true, 'events' => ['repo:push'],
            ],
            'acme/api',
        ],
    };
}

it('reads every page of hooks, so the second page is neither duplicated nor missed', function (string $provider) {
    [$page, $hook, $path] = hookPagesFor($provider);
    $target = 'https://app.test/hooks/target';

    $first = array_map(fn (int $id): array => $hook("https://other.test/{$id}", $id), range(1, 10));
    $second = [$hook($target, 11)];

    Http::fake(function ($request) use ($page, $first, $second) {
        if ($request->method() !== 'GET') {
            return Http::response([], $request->method() === 'DELETE' ? 204 : 201);
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['page'] ?? '1') === '1' ? $page($first, true) : $page($second, false);
    });

    $webhooks = Git::provider($provider, Token::from('x'))->repo($path)->webhooks();

    expect($webhooks->all())->toHaveCount(11)
        ->and($webhooks->registered($target))->toBeTrue()
        ->and($webhooks->register(url: $target)->url)->toBe($target)
        ->and($webhooks->deleteByUrl($target))->toBeTrue();

    Http::assertNotSent(fn ($request): bool => $request->method() === 'POST');
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE');
})->with(['github', 'gitlab', 'bitbucket']);

it('reports a partial bitbucket pull request subscription by its native events', function (array $native, array $reported) {
    // `pull_request` promises every pull request transition (created, updated, merged,
    // declined); a hook subscribed to fewer must not read as one that gets them all.
    Http::fake(['*/repositories/*/hooks*' => Http::response(['pagelen' => 10, 'page' => 1, 'size' => 1, 'values' => [[
        'type' => 'webhook_subscription', 'uuid' => '{0b1e7a52-3c4d-4e5f-8a9b-0c1d2e3f4a5b}',
        'url' => 'https://app.test/hook', 'description' => 'hook', 'subject_type' => 'repository',
        'active' => true, 'events' => $native,
    ]]])]);

    expect(Git::bitbucket(Token::from('x'))->listWebhooks('acme/api')[0]->events)->toBe($reported);
})->with([
    'only created' => [['pullrequest:created'], ['pullrequest:created']],
    'three of four' => [['pullrequest:created', 'pullrequest:updated', 'pullrequest:fulfilled'], ['pullrequest:created', 'pullrequest:updated', 'pullrequest:fulfilled']],
    'all four' => [['pullrequest:created', 'pullrequest:updated', 'pullrequest:fulfilled', 'pullrequest:rejected'], ['pull_request']],
    'push and all four' => [['repo:push', 'pullrequest:rejected', 'pullrequest:created', 'pullrequest:updated', 'pullrequest:fulfilled'], ['push', 'pull_request']],
    'push' => [['repo:push'], ['push']],
]);
