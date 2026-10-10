<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Enums\WorkflowConclusion;
use RoundlyConsulting\Git\Enums\WorkflowStatus;
use RoundlyConsulting\Git\Query\WorkflowJobQuery;
use RoundlyConsulting\Git\Query\WorkflowRunQuery;

/** A query whose fetcher hands back the filters it was given, as the page's only item. */
function capturingRunQuery(): WorkflowRunQuery
{
    return new WorkflowRunQuery(fn (array $filters, int $page, int $perPage): Page => new Page([$filters], $perPage, $page, false));
}

function capturingJobQuery(): WorkflowJobQuery
{
    return new WorkflowJobQuery(fn (array $filters, int $page, int $perPage): Page => new Page([$filters], $perPage, $page, false));
}

it('collects every run filter', function (): void {
    $filters = capturingRunQuery()
        ->branch('main')
        ->event('workflow_dispatch')
        ->status(WorkflowStatus::InProgress)
        ->actor('octocat')
        ->headSha(str_repeat('a', 40))
        ->createdAfter(Carbon::parse('2026-03-01 12:30:00', 'Europe/Bratislava'))
        ->createdBefore(Carbon::parse('2026-03-02T00:00:00Z'))
        ->excludePullRequests()
        ->first();

    expect($filters)->toBe([
        'branch' => 'main',
        'event' => 'workflow_dispatch',
        'status' => 'in_progress',
        'actor' => 'octocat',
        'headSha' => str_repeat('a', 40),
        // UTC, to the second: GitHub's `created` search syntax.
        'createdAfter' => '2026-03-01T11:30:00Z',
        'createdBefore' => '2026-03-02T00:00:00Z',
        'excludePullRequests' => true,
    ]);
});

it('filters by a conclusion through the same status filter, as github does', function (): void {
    expect(capturingRunQuery()->status(WorkflowConclusion::Failure)->first())->toBe(['status' => 'failure']);
});

it('refuses to filter by the unknown placeholder, which github never sends', function (WorkflowStatus|WorkflowConclusion $unknown): void {
    expect(fn () => capturingRunQuery()->status($unknown))->toThrow(InvalidArgumentException::class, 'unknown');
})->with([
    'status' => [fn (): WorkflowStatus => WorkflowStatus::Unknown],
    'conclusion' => [fn (): WorkflowConclusion => WorkflowConclusion::Unknown],
]);

it('does not move the caller\'s carbon instance when normalising it to utc', function (): void {
    $since = Carbon::parse('2026-03-01 12:30:00', 'Europe/Bratislava');

    capturingRunQuery()->createdAfter($since);

    expect($since->getTimezone()->getName())->toBe('Europe/Bratislava');
});

it('reads the latest attempt by default, every attempt or one attempt on request', function (): void {
    expect(capturingJobQuery()->first())->toBe([])
        ->and(capturingJobQuery()->allAttempts()->first())->toBe(['filter' => 'all'])
        ->and(capturingJobQuery()->attempt(2)->first())->toBe(['attempt' => 2]);
});

it('refuses every attempt and one attempt together, and an attempt below 1', function (): void {
    expect(fn () => capturingJobQuery()->allAttempts()->attempt(2))->toThrow(InvalidArgumentException::class)
        ->and(fn () => capturingJobQuery()->attempt(2)->allAttempts())->toThrow(InvalidArgumentException::class)
        ->and(fn () => capturingJobQuery()->attempt(0))->toThrow(InvalidArgumentException::class, '[0]');
});

it('carries a total on a page, which defaults to unknown', function (): void {
    expect((new Page([], 30, 1, false, total: 1000))->total)->toBe(1000)
        ->and((new Page([], 30, 1, false))->total)->toBeNull()
        ->and((new Page([], 30, 1, false, total: 3))->toArray())->toMatchArray(['total' => 3]);
});
