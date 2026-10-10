<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Activity;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Enums\ActivityType;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\Tests\testable\BaseProvider;

/*
 * A repository's activity feed: who pushed, force-pushed, created, deleted or merged into a
 * ref, newest first. GitHub pages it by CURSOR (`before` / `after` in the `Link` header),
 * not by page number, so the page hands back `nextCursor`.
 */

/** @return array<string, mixed> */
function activityPayload(int $id, string $type = 'push', string $ref = 'refs/heads/main'): array
{
    return [
        'id' => $id,
        'node_id' => "node-{$id}",
        'before' => str_repeat('1', 40),
        'after' => str_repeat('2', 40),
        'ref' => $ref,
        'timestamp' => '2026-01-01T10:00:00Z',
        'activity_type' => $type,
        'actor' => ['login' => 'mona', 'id' => 7, 'avatar_url' => 'https://avatars.test/mona'],
    ];
}

it('maps the documented activity payload', function (): void {
    Http::fake(['*/repos/octocat/Hello-World/activity*' => snapshot('github/activity')]);

    $page = github()->repo('octocat/Hello-World')->activity();
    $activity = $page->first();

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->provider)->toBe(ProviderName::Github)
        ->and($activity->id)->toBe('1296269')
        ->and($activity->type)->toBe(ActivityType::ForcePush)
        ->and($activity->ref)->toBe('refs/heads/main')
        ->and($activity->before)->toBe('6dcb09b5b57875f334f61aebed695e2e4193db5e')
        ->and($activity->after)->toBe('827efc6d56897b048c772eb4087f854f46256132')
        ->and($activity->actor)->toBeInstanceOf(Owner::class)
        ->and($activity->actor?->id)->toBe('1')
        ->and($activity->actor?->name)->toBe('octocat')
        ->and($activity->occurredAt->toIso8601ZuluString())->toBe('2011-01-26T19:06:43Z')
        ->and($activity->raw()['node_id'])->toBe('MDEwOlJlcG9zaXRvcnkxMjk2MjY5')
        ->and($activity->toArray())->toMatchArray(['type' => 'force_push'])->not->toHaveKey('raw')
        ->and($page->hasMore)->toBeFalse()
        ->and($page->nextCursor)->toBeNull();
});

it('sends the ref, the page size and the cursor', function (): void {
    Http::fake(['*' => Http::response([])]);

    github()->repo('o/r')->activity('refs/heads/main', perPage: 50, cursor: 'Y3Vyc29yOnYy==');

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_contains($request->url(), '/repos/o/r/activity?')
            && $query === ['ref' => 'refs/heads/main', 'per_page' => '50', 'after' => 'Y3Vyc29yOnYy=='];
    });
});

it('sends no ref and no cursor when none is asked for, and caps the page at 100', function (): void {
    Http::fake(['*' => Http::response([])]);

    expect(github()->activity('o/r', perPage: 500)->perPage)->toBe(100);

    Http::assertSent(function (Request $request): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query === ['per_page' => '100'];
    });
});

it('reads the next cursor off the link header', function (): void {
    Http::fake(['*' => Http::response(
        [activityPayload(1), activityPayload(2)],
        200,
        ['Link' => '<https://api.github.com/repositories/9/activity?per_page=2&before=QQ%3D%3D>; rel="prev", <https://api.github.com/repositories/9/activity?per_page=2&after=Y3Vyc29yOnYyOpK0%3D%3D>; rel="next"'],
    )]);

    $page = github()->activity('o/r', perPage: 2);

    expect($page->items)->toHaveCount(2)
        ->and($page->hasMore)->toBeTrue()
        ->and($page->nextCursor)->toBe('Y3Vyc29yOnYyOpK0==')
        ->and($page->page)->toBe(1);
});

it('reads an all-zero before or after as no commit, and a missing actor as null', function (): void {
    // A branch creation has no commit before it, a deletion none after it.
    $created = ['before' => str_repeat('0', 40), 'actor' => null] + activityPayload(3, 'branch_creation');
    $deleted = ['after' => str_repeat('0', 40)] + activityPayload(4, 'branch_deletion');

    Http::fake(['*' => Http::response([$created, $deleted])]);

    [$creation, $deletion] = github()->activity('o/r')->items;

    expect($creation->before)->toBeNull()
        ->and($creation->after)->toBe(str_repeat('2', 40))
        ->and($creation->actor)->toBeNull()
        ->and($creation->type)->toBe(ActivityType::BranchCreation)
        ->and($deletion->after)->toBeNull()
        ->and($deletion->type)->toBe(ActivityType::BranchDeletion);
});

it('reads an activity type github adds later as unknown', function (): void {
    Http::fake(['*' => Http::response([activityPayload(5, 'tag_creation')])]);

    expect(github()->activity('o/r')->first()?->type)->toBe(ActivityType::Unknown);
});

it('refuses an activity without a usable id rather than making one up', function (): void {
    $payload = activityPayload(6);
    unset($payload['id']);

    Http::fake(['*' => Http::response([$payload])]);

    expect(fn () => github()->activity('o/r'))->toThrow(InvalidArgumentException::class, 'id');
});

it('leaves a refused activity read to the caller as a request exception', function (int $status): void {
    // An installation may not be allowed to read it; the caller decides on a fallback.
    Http::fake(['*' => Http::response(['message' => 'Resource not accessible by integration'], $status)]);

    expect(fn () => github()->activity('o/r'))->toThrow(RequestException::class);
})->with([403, 404]);

it('reports repository activity on github only', function (): void {
    expect(github()->supports(Feature::RepositoryActivity))->toBeTrue()
        ->and(gitlab()->supports(Feature::RepositoryActivity))->toBeFalse()
        ->and(bitbucket()->supports(Feature::RepositoryActivity))->toBeFalse()
        ->and(Feature::RepositoryActivity->value)->toBe('activity')
        ->and(fn () => gitlab()->repo('g/p')->activity())->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => bitbucket()->repo('w/r')->activity())->toThrow(FeatureNotSupportedException::class)
        ->and(fn () => (new BaseProvider)->activity('o/r'))->toThrow(FeatureNotSupportedException::class);
});

describe('the fake', function (): void {
    function fakeActivity(int $id, string $ref): Activity
    {
        return new Activity(
            provider: ProviderName::Github,
            id: (string) $id,
            type: ActivityType::Push,
            ref: $ref,
            before: null,
            after: str_repeat((string) ($id % 10), 40),
            actor: null,
            occurredAt: Carbon::parse('2026-01-01')->subMinutes($id),
        );
    }

    it('filters the seeded activity by ref, a bare branch name meaning refs/heads/', function (): void {
        $fake = Git::fake();
        $fake->fakeFor(ProviderName::Github)->seedActivity([
            fakeActivity(1, 'refs/heads/main'),
            fakeActivity(2, 'refs/heads/develop'),
            fakeActivity(3, 'refs/heads/main'),
        ]);

        expect(array_map(fn (Activity $a): string => $a->id, Git::github()->repo('acme/app')->activity('main')->items))->toBe(['1', '3'])
            ->and(array_map(fn (Activity $a): string => $a->id, Git::github()->repo('acme/app')->activity('refs/heads/develop')->items))->toBe(['2'])
            ->and(Git::github()->repo('acme/app')->activity()->items)->toHaveCount(3);

        $fake->assertSent(ProviderName::Github, 'activity', fn (string $path, ?string $ref): bool => $path === 'acme/app' && $ref === 'main');
    });

    it('pages the seeded activity by an opaque cursor', function (): void {
        Git::fake()->fakeFor(ProviderName::Github)->seedActivity(array_map(fn (int $i): Activity => fakeActivity($i, 'refs/heads/main'), range(1, 5)));

        $first = Git::github()->activity('acme/app', perPage: 2);
        $second = Git::github()->activity('acme/app', perPage: 2, cursor: $first->nextCursor);
        $last = Git::github()->activity('acme/app', perPage: 2, cursor: $second->nextCursor);

        expect(array_map(fn (Activity $a): string => $a->id, $first->items))->toBe(['1', '2'])
            ->and($first->hasMore)->toBeTrue()
            ->and($first->nextCursor)->toBe('fake:2')
            ->and(array_map(fn (Activity $a): string => $a->id, $second->items))->toBe(['3', '4'])
            ->and(array_map(fn (Activity $a): string => $a->id, $last->items))->toBe(['5'])
            ->and($last->hasMore)->toBeFalse()
            ->and($last->nextCursor)->toBeNull()
            ->and(Git::github()->activity('acme/app')->items)->toHaveCount(5);
    });

    it('answers an empty page when nothing is seeded, and refuses a cursor it never handed out', function (): void {
        Git::fake();

        expect(Git::github()->activity('acme/app')->items)->toBe([])
            ->and(fn () => Git::github()->activity('acme/app', cursor: 'Y3Vyc29y'))->toThrow(InvalidArgumentException::class, 'cursor')
            ->and(fn () => Git::gitlab()->activity('g/p'))->toThrow(FeatureNotSupportedException::class);
    });
});

it('exposes the activity types through the enum helpers', function (): void {
    expect(ActivityType::values()->all())->toBe(['push', 'force_push', 'branch_creation', 'branch_deletion', 'pr_merge', 'merge_queue_merge', 'unknown']);
});
