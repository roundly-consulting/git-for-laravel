<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\DispatchedWorkflow;
use RoundlyConsulting\Git\Dto\Input\InstallationTokenScope;
use RoundlyConsulting\Git\Dto\Input\NewWorkflowDispatch;
use RoundlyConsulting\Git\Dto\WorkflowJob;
use RoundlyConsulting\Git\Dto\WorkflowRun;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\WorkflowConclusion;
use RoundlyConsulting\Git\Enums\WorkflowStatus;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\Handles\ActionsHandle;
use RoundlyConsulting\Git\Query\WorkflowJobQuery;
use RoundlyConsulting\Git\Query\WorkflowRunQuery;
use RoundlyConsulting\Git\Tests\testable\BaseProvider;

/*
 * GitHub Actions through `repo(...)->actions()`: dispatch a workflow, find and read its
 * runs and jobs, cancel a run. Every call goes through the drivers' shared `get()` /
 * `send()`, so the limiter, `Retry-After`, the 401 mapping and the ETag cache apply.
 */

/** The decoded query string of a recorded request. */
function actionsQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

describe('dispatch()', function (): void {
    it('posts the exact body and reads the run github started', function (): void {
        Carbon::setTestNow('2026-03-01T10:00:00Z');
        Http::fake(['*/repos/octo-org/octo-repo/actions/workflows/deploy.yml/dispatches' => Http::response(snapshotData('github/dispatch-200'), 200)]);

        $dispatched = github()->repo('octo-org/octo-repo')->actions()->dispatch('deploy.yml', 'refs/heads/main', ['request_id' => 'r-1', 'dry_run' => false]);

        expect($dispatched)->toBeInstanceOf(DispatchedWorkflow::class)
            ->provider->toBe(ProviderName::Github)
            ->workflow->toBe('deploy.yml')
            ->ref->toBe('refs/heads/main')
            ->runId->toBe('30433642')
            ->apiUrl->toBe('https://api.github.com/repos/octo-org/octo-repo/actions/runs/30433642')
            ->url->toBe('https://github.com/octo-org/octo-repo/actions/runs/30433642')
            ->and($dispatched->dispatchedAt->toIso8601ZuluString())->toBe('2026-03-01T10:00:00Z')
            ->and($dispatched->raw())->toBe(snapshotData('github/dispatch-200'));

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->data() === ['ref' => 'refs/heads/main', 'inputs' => ['request_id' => 'r-1', 'dry_run' => false], 'return_run_details' => true]
            && $request->hasHeader('Authorization', 'Bearer token-value'));

        Carbon::setTestNow();
    });

    it('leaves the inputs out when there are none, and takes a numeric workflow id', function (): void {
        Http::fake(['*' => Http::response(null, 204)]);

        github()->repo('o/r')->actions()->dispatch('159038', 'main');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/repos/o/r/actions/workflows/159038/dispatches')
            && $request->data() === ['ref' => 'main', 'return_run_details' => true]);
    });

    it('answers a 204 without run details as a dispatch with no run id', function (): void {
        Http::fake(['*' => Http::response(null, 204)]);

        $dispatched = github()->repo('o/r')->actions()->dispatch('deploy.yml', 'main');

        expect($dispatched->runId)->toBeNull()
            ->and($dispatched->apiUrl)->toBeNull()
            ->and($dispatched->url)->toBeNull()
            ->and($dispatched->raw())->toBe([]);
    });

    it('does not ask enterprise server for run details it may not know', function (): void {
        config()->set('git.providers.github.url', 'https://ghe.acme.io/api/v3');
        Http::fake(['*' => Http::response(null, 204)]);

        expect(github()->repo('o/r')->actions()->dispatch('deploy.yml', 'main')->runId)->toBeNull();

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://ghe.acme.io/api/v3/repos/o/r/actions/workflows/deploy.yml/dispatches')
            && $request->data() === ['ref' => 'main']);
    });

    it('asks ghe.com for run details like github.com', function (): void {
        config()->set('git.providers.github.url', 'https://api.acme.ghe.com');
        Http::fake(['*' => Http::response(snapshotData('github/dispatch-200'))]);

        expect(github()->repo('o/r')->actions()->dispatch('deploy.yml', 'main')->runId)->toBe('30433642');

        Http::assertSent(fn (Request $request): bool => ($request->data()['return_run_details'] ?? null) === true);
    });

    it('never retries a dispatch: a lost answer plus a retry would start a second build', function (): void {
        config()->set('git.providers.github.retry', ['times' => 3, 'backoff' => 0]);
        Http::fake(['*' => Http::response(['message' => 'Bad Gateway'], 502)]);

        expect(fn () => github()->repo('o/r')->actions()->dispatch('deploy.yml', 'main'))->toThrow(RequestException::class);

        Http::assertSentCount(1);
    });

    it('dispatches with a scoped installation token minted for actions: write', function (): void {
        Http::fake([
            '*/app/installations/999/access_tokens' => Http::response(['token' => 'ghs_actions', 'expires_at' => Carbon::now()->addHour()->toIso8601String()]),
            '*/actions/workflows/*' => Http::response(snapshotData('github/dispatch-200')),
        ]);

        $credential = appCredentials()->forScope(new InstallationTokenScope(repositories: ['octo-org/octo-repo'], permissions: ['actions' => 'write']));

        expect(Git::github($credential)->repo('octo-org/octo-repo')->actions()->dispatch('deploy.yml', 'main')->runId)->toBe('30433642');

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/access_tokens')
            && $request['permissions'] === ['actions' => 'write']
            && $request['repositories'] === ['octo-repo']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/dispatches')
            && $request->hasHeader('Authorization', 'Bearer ghs_actions'));
    });

    it('refuses a bad workflow, ref or input before any request', function (Closure $call): void {
        Http::fake();

        expect($call)->toThrow(InvalidArgumentException::class);

        Http::assertNothingSent();
    })->with([
        'path workflow' => [fn () => github()->repo('o/r')->actions()->dispatch('a/b.yml', 'main')],
        'traversal workflow' => [fn () => github()->repo('o/r')->actions()->dispatch('../x.yml', 'main')],
        'empty ref' => [fn () => github()->repo('o/r')->actions()->dispatch('deploy.yml', '')],
        'nested input' => [fn () => github()->repo('o/r')->actions()->dispatch('deploy.yml', 'main', ['a' => ['b']])],
    ]);
});

describe('runs()', function (): void {
    it('reads a workflow\'s runs with every filter on the query string', function (): void {
        Http::fake(['*' => Http::response(['total_count' => 0, 'workflow_runs' => []])]);

        github()->repo('o/r')->actions()->runs('deploy.yml')
            ->branch('main')
            ->event('workflow_dispatch')
            ->status(WorkflowStatus::InProgress)
            ->actor('octocat')
            ->headSha(str_repeat('a', 40))
            ->createdAfter(Carbon::parse('2026-03-01T10:00:00Z'))
            ->createdBefore(Carbon::parse('2026-03-02T10:00:00Z'))
            ->excludePullRequests()
            ->perPage(50)
            ->get(2);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/repos/o/r/actions/workflows/deploy.yml/runs?')
            && actionsQuery($request) === [
                'branch' => 'main',
                'event' => 'workflow_dispatch',
                'status' => 'in_progress',
                'actor' => 'octocat',
                'head_sha' => str_repeat('a', 40),
                'created' => '2026-03-01T10:00:00Z..2026-03-02T10:00:00Z',
                'exclude_pull_requests' => 'true',
                'page' => '2',
                'per_page' => '50',
            ]);
    });

    it('sends an open-ended created range as >= or <=', function (Closure $filter, string $created): void {
        Http::fake(['*' => Http::response(['total_count' => 0, 'workflow_runs' => []])]);

        $filter(github()->repo('o/r')->actions()->runs())->get();

        Http::assertSent(fn (Request $request): bool => (actionsQuery($request)['created'] ?? null) === $created);
    })->with([
        'after only' => [fn (WorkflowRunQuery $q) => $q->createdAfter(Carbon::parse('2026-03-01T10:00:00Z')), '>=2026-03-01T10:00:00Z'],
        'before only' => [fn (WorkflowRunQuery $q) => $q->createdBefore(Carbon::parse('2026-03-01T10:00:00Z')), '<=2026-03-01T10:00:00Z'],
    ]);

    it('reads the repository\'s runs when no workflow is named, with total and hasMore', function (): void {
        Http::fake(['*' => Http::response(snapshotData('github/workflow-runs'), 200, [
            'Link' => '<https://api.github.com/repositories/1/actions/runs?page=2>; rel="next"',
        ])]);

        $page = github()->repo('octo-org/octo-repo')->actions()->runs()->perPage(2)->get();

        expect($page->items)->toHaveCount(2)
            ->and($page->items[0])->toBeInstanceOf(WorkflowRun::class)
            ->and($page->items[0]->conclusion)->toBe(WorkflowConclusion::Success)
            ->and($page->total)->toBe(2)
            ->and($page->hasMore)->toBeTrue();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/repos/octo-org/octo-repo/actions/runs?')
            && actionsQuery($request) === ['page' => '1', 'per_page' => '2']);
    });

    it('walks every page lazily until github stops linking a next one', function (): void {
        $runs = snapshotData('github/workflow-runs');

        Http::fakeSequence('*/actions/runs*')
            ->push($runs, 200, ['Link' => '<https://api.github.com/x?page=2>; rel="next"'])
            ->push($runs);

        expect(github()->repo('o/r')->actions()->runs()->lazy()->count())->toBe(4);
    });
});

it('reads one run by id', function (): void {
    Http::fake(['*/repos/octo-org/octo-repo/actions/runs/30433642' => snapshot('github/workflow-run')]);

    $run = github()->repo('octo-org/octo-repo')->actions()->run(30433642);

    expect($run)->toBeInstanceOf(WorkflowRun::class)
        ->id->toBe('30433642')
        ->status->toBe(WorkflowStatus::Queued);
});

describe('jobs()', function (): void {
    it('reads the latest attempt\'s jobs by default', function (): void {
        Http::fake(['*' => snapshot('github/jobs')]);

        $page = github()->repo('o/r')->actions()->jobs('29679449')->get();

        expect($page->first())->toBeInstanceOf(WorkflowJob::class)
            ->and($page->first()?->steps)->toHaveCount(10)
            ->and($page->total)->toBe(1);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/repos/o/r/actions/runs/29679449/jobs?')
            && actionsQuery($request) === ['page' => '1', 'per_page' => '30']);
    });

    it('reads every attempt\'s jobs on request', function (): void {
        Http::fake(['*' => snapshot('github/jobs')]);

        github()->repo('o/r')->actions()->jobs(29679449)->allAttempts()->get();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/actions/runs/29679449/jobs?')
            && (actionsQuery($request)['filter'] ?? null) === 'all');
    });

    it('reads one attempt\'s jobs from the attempts endpoint', function (): void {
        Http::fake(['*' => snapshot('github/jobs')]);

        github()->repo('o/r')->actions()->jobs(29679449)->attempt(2)->get();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/actions/runs/29679449/attempts/2/jobs?')
            && ! array_key_exists('filter', actionsQuery($request)));
    });

    it('answers "did this run start any job" with one small page', function (): void {
        Http::fake(['*' => Http::response(['total_count' => 3, 'jobs' => [snapshotData('github/jobs')['jobs'][0]]])]);

        expect(github()->repo('o/r')->actions()->jobs(1)->perPage(1)->get()->total)->toBe(3);
    });

    it('refuses every attempt and one attempt together', function (): void {
        expect(fn () => github()->repo('o/r')->actions()->jobs(1)->attempt(2)->allAttempts())->toThrow(InvalidArgumentException::class);
    });
});

describe('cancel()', function (): void {
    it('answers an accepted cancel as true', function (): void {
        Http::fake(['*/repos/o/r/actions/runs/7/cancel' => Http::response([], 202)]);

        expect(github()->repo('o/r')->actions()->cancel(7))->toBeTrue();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/actions/runs/7/cancel'));
    });

    it('answers a run that already finished (409) as false', function (): void {
        Http::fake(['*' => Http::response(['message' => 'Cannot cancel a workflow run that is completed.'], 409)]);

        expect(github()->repo('o/r')->actions()->cancel('7'))->toBeFalse();
    });

    it('throws anything else', function (int $status): void {
        Http::fake(['*' => Http::response(['message' => 'nope'], $status)]);

        expect(fn () => github()->repo('o/r')->actions()->cancel(7))->toThrow(RequestException::class);
    })->with([403, 404]);
});

it('needs a credential to dispatch or cancel', function (Closure $call): void {
    Http::fake();

    expect($call)->toThrow(InvalidCredentialsException::class, 'GitHub');

    Http::assertNothingSent();
})->with([
    'dispatch' => [fn () => Git::github()->repo('o/r')->actions()->dispatch('deploy.yml', 'main')],
    'cancel' => [fn () => Git::github()->repo('o/r')->actions()->cancel(7)],
]);

it('refuses an id that is not numeric before any request', function (Closure $call): void {
    Http::fake();

    expect($call)->toThrow(OutOfScopeException::class);

    Http::assertNothingSent();
})->with([
    'run' => [fn () => github()->repo('o/r')->actions()->run('../7')],
    'negative run' => [fn () => github()->repo('o/r')->actions()->run(-7)],
    'jobs' => [fn () => github()->repo('o/r')->actions()->jobs('7/jobs')],
    'cancel' => [fn () => github()->repo('o/r')->actions()->cancel('7?x=1')],
    'runs workflow' => [fn () => github()->repo('o/r')->actions()->runs('a/b.yml')],
    'flat driver run' => [fn () => github()->workflowRun('o/r', 'abc')],
    'flat driver dispatch' => [fn () => github()->dispatchWorkflow('../o/r', new NewWorkflowDispatch('deploy.yml', 'main'))],
]);

it('scopes the actions handle to its repository', function (): void {
    expect(github()->repo('acme/app')->actions())->toBeInstanceOf(ActionsHandle::class)
        ->and(github()->repo('acme/app')->actions()->path())->toBe('acme/app')
        ->and(github()->repo('acme/app')->actions()->runs())->toBeInstanceOf(WorkflowRunQuery::class)
        ->and(github()->repo('acme/app')->actions()->jobs(1))->toBeInstanceOf(WorkflowJobQuery::class);
});

it('reports the actions features on github only', function (): void {
    $features = [Feature::DispatchWorkflow, Feature::ListWorkflowRuns, Feature::FindWorkflowRun, Feature::ListWorkflowJobs, Feature::CancelWorkflowRun];

    expect(github()->supportsAll(...$features))->toBeTrue()
        ->and(gitlab()->supportsAny(...$features))->toBeFalse()
        ->and(bitbucket()->supportsAny(...$features))->toBeFalse()
        ->and(array_map(fn (Feature $feature): string => $feature->value, $features))
        ->toBe(['dispatch_workflow', 'workflow_runs', 'workflow_run', 'workflow_jobs', 'cancel_workflow_run']);

    foreach ($features as $feature) {
        expect($feature->description())->not->toBeEmpty();
    }
});

it('refuses actions on gitlab, bitbucket and a driver that does not implement them', function (Closure $call): void {
    Http::fake();

    expect($call)->toThrow(FeatureNotSupportedException::class);

    Http::assertNothingSent();
})->with([
    'gitlab dispatch' => [fn () => gitlab()->repo('g/p')->actions()->dispatch('deploy.yml', 'main')],
    'gitlab runs' => [fn () => gitlab()->repo('g/p')->actions()->runs()],
    'gitlab run' => [fn () => gitlab()->repo('g/p')->actions()->run(1)],
    'bitbucket jobs' => [fn () => bitbucket()->repo('w/r')->actions()->jobs(1)],
    'bitbucket cancel' => [fn () => bitbucket()->repo('w/r')->actions()->cancel(1)],
    'base dispatch' => [fn () => (new BaseProvider)->dispatchWorkflow('o/r', new NewWorkflowDispatch('deploy.yml', 'main'))],
    'base runs' => [fn () => (new BaseProvider)->workflowRuns('o/r')],
    'base run' => [fn () => (new BaseProvider)->workflowRun('o/r', '1')],
    'base jobs' => [fn () => (new BaseProvider)->workflowJobs('o/r', '1')],
    'base cancel' => [fn () => (new BaseProvider)->cancelWorkflowRun('o/r', '1')],
]);

it('serves a polled run from the conditional cache on a 304', function (): void {
    config()->set('git.cache.enabled', true);

    Http::fakeSequence('*/actions/runs/30433642')
        ->push(snapshotData('github/workflow-run'), 200, ['ETag' => '"run-v1"'])
        ->push(null, 304, ['ETag' => '"run-v1"']);

    $actions = github()->repo('octo-org/octo-repo')->actions();

    expect($actions->run(30433642)->id)->toBe('30433642')
        ->and($actions->run(30433642)->id)->toBe('30433642');

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('If-None-Match', '"run-v1"'));
});
