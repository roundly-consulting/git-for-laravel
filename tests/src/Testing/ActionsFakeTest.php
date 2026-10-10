<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Git\Dto\Branch;
use RoundlyConsulting\Git\Dto\Input\NewWorkflowDispatch;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\WorkflowJob;
use RoundlyConsulting\Git\Dto\WorkflowRun;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\WorkflowConclusion;
use RoundlyConsulting\Git\Enums\WorkflowStatus;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Facades\Git;

/*
 * The fake has to carry a host's dispatch → find → poll → cancel test honestly: it applies
 * the run filters and the job attempts (an ignored filter would hand back another
 * workflow's run in exactly the correlation flow this exists for), and a dispatch creates
 * the queued run it reports.
 */

beforeEach(function (): void {
    fakeCredentials();
});

function fakeRun(string $id, array $overrides = []): WorkflowRun
{
    $values = array_replace([
        'workflowId' => '100',
        'displayTitle' => "Deploy {$id}",
        'status' => WorkflowStatus::Completed,
        'conclusion' => WorkflowConclusion::Success,
        'event' => 'push',
        'path' => '.github/workflows/deploy.yml',
        'headSha' => str_repeat('a', 40),
        'headBranch' => 'main',
        'createdAt' => Carbon::parse('2026-03-01T10:00:00Z')->addMinutes((int) $id),
        'actor' => new Owner(id: '1', name: 'octocat', avatar: null),
    ], $overrides);

    return new WorkflowRun(
        provider: ProviderName::Github,
        id: $id,
        workflowId: $values['workflowId'],
        displayTitle: $values['displayTitle'],
        status: $values['status'],
        event: $values['event'],
        path: $values['path'],
        headSha: $values['headSha'],
        createdAt: $values['createdAt'],
        updatedAt: $values['createdAt'],
        conclusion: $values['conclusion'],
        headBranch: $values['headBranch'],
        actor: $values['actor'],
    );
}

function fakeJob(string $id, string $runId, int $attempt): WorkflowJob
{
    return new WorkflowJob(ProviderName::Github, $id, $runId, "job {$id}", WorkflowStatus::Completed, str_repeat('a', 40), WorkflowConclusion::Success, runAttempt: $attempt);
}

/** @param iterable<WorkflowRun> $runs */
function runIds(iterable $runs): array
{
    return array_values(array_map(fn (WorkflowRun $run): string => $run->id, is_array($runs) ? $runs : iterator_to_array($runs)));
}

describe('runs()', function (): void {
    it('answers an empty page when nothing is seeded', function (): void {
        Git::fake();

        $page = Git::github()->repo('acme/app')->actions()->runs()->get();

        expect($page->items)->toBe([])
            ->and($page->total)->toBe(0)
            ->and($page->hasMore)->toBeFalse();
    });

    it('answers the seeded runs newest first, paged, with their total', function (): void {
        Git::fake()->fakeFor(ProviderName::Github)->seedWorkflowRuns([fakeRun('1'), fakeRun('3'), fakeRun('2')]);

        $query = Git::github()->repo('acme/app')->actions()->runs()->perPage(2);

        expect(runIds($query->get()->items))->toBe(['3', '2'])
            ->and($query->get()->total)->toBe(3)
            ->and($query->get()->hasMore)->toBeTrue()
            ->and(runIds($query->get(2)->items))->toBe(['1'])
            ->and(runIds($query->lazy()))->toBe(['3', '2', '1']);
    });

    it('honours the workflow, by file name or by id', function (): void {
        Git::fake()->fakeFor(ProviderName::Github)->seedWorkflowRuns([
            fakeRun('1'),
            fakeRun('2', ['path' => '.github/workflows/ci.yml', 'workflowId' => '200']),
            // GitHub may report a reusable workflow's path with its ref.
            fakeRun('3', ['path' => '.github/workflows/deploy.yml@main']),
        ]);

        $actions = Git::github()->repo('acme/app')->actions();

        expect(runIds($actions->runs('deploy.yml')->get()->items))->toBe(['3', '1'])
            ->and(runIds($actions->runs('ci.yml')->get()->items))->toBe(['2'])
            ->and(runIds($actions->runs('200')->get()->items))->toBe(['2'])
            ->and(runIds($actions->runs('100')->get()->items))->toBe(['3', '1'])
            ->and($actions->runs('build.yaml')->get()->items)->toBe([]);
    });

    it('honours every filter', function (Closure $filter, array $expected): void {
        Git::fake()->fakeFor(ProviderName::Github)->seedWorkflowRuns([
            fakeRun('1'),
            fakeRun('2', ['headBranch' => 'develop']),
            fakeRun('3', ['event' => 'workflow_dispatch']),
            fakeRun('4', ['status' => WorkflowStatus::InProgress, 'conclusion' => null]),
            fakeRun('5', ['conclusion' => WorkflowConclusion::Failure]),
            fakeRun('6', ['actor' => new Owner(id: '2', name: 'hubot', avatar: null)]),
            fakeRun('7', ['headSha' => str_repeat('b', 40)]),
        ]);

        expect(runIds($filter(Git::github()->repo('acme/app')->actions()->runs())->get()->items))->toBe($expected);
    })->with([
        'branch' => [fn ($q) => $q->branch('develop'), ['2']],
        'event' => [fn ($q) => $q->event('workflow_dispatch'), ['3']],
        'status' => [fn ($q) => $q->status(WorkflowStatus::InProgress), ['4']],
        'completed status' => [fn ($q) => $q->status(WorkflowStatus::Completed)->branch('main')->event('push')->actor('octocat')->headSha(str_repeat('a', 40)), ['5', '1']],
        'conclusion' => [fn ($q) => $q->status(WorkflowConclusion::Failure), ['5']],
        'actor' => [fn ($q) => $q->actor('hubot'), ['6']],
        'head sha' => [fn ($q) => $q->headSha(str_repeat('b', 40)), ['7']],
        'created after' => [fn ($q) => $q->createdAfter(Carbon::parse('2026-03-01T10:06:00Z')), ['7', '6']],
        'created before' => [fn ($q) => $q->createdBefore(Carbon::parse('2026-03-01T10:02:00Z')), ['2', '1']],
        'created between' => [fn ($q) => $q->createdAfter(Carbon::parse('2026-03-01T10:03:00Z'))->createdBefore(Carbon::parse('2026-03-01T10:04:00Z')), ['4', '3']],
        'excluding pull requests changes nothing' => [fn ($q) => $q->excludePullRequests()->branch('develop'), ['2']],
    ]);

    it('records the query and the filters it was read with', function (): void {
        $fake = Git::fake();

        Git::github()->repo('acme/app')->actions()->runs('deploy.yml')->branch('main')->get();

        $fake->assertSent(ProviderName::Github, 'workflowRuns', fn (string $path, ?string $workflow): bool => $path === 'acme/app' && $workflow === 'deploy.yml');
        $fake->assertSent(ProviderName::Github, 'workflowRuns.get', fn (array $filters): bool => $filters === ['branch' => 'main']);
    });
});

describe('run()', function (): void {
    it('answers the seeded run, and names the seeder otherwise', function (): void {
        Git::fake()->fakeFor(ProviderName::Github)->seedWorkflowRuns([fakeRun('1'), fakeRun('2')]);

        expect(Git::github()->repo('acme/app')->actions()->run(2)->displayTitle)->toBe('Deploy 2')
            ->and(fn () => Git::github()->repo('acme/app')->actions()->run(9))->toThrow(RuntimeException::class, 'seedWorkflowRun()');
    });

    it('takes the next poll\'s state from seedWorkflowRun(), an upsert by id', function (): void {
        $github = Git::fake()->fakeFor(ProviderName::Github);
        $github->seedWorkflowRun(fakeRun('5', ['status' => WorkflowStatus::InProgress, 'conclusion' => null]));

        $actions = Git::github()->repo('acme/app')->actions();

        expect($actions->run(5)->isActive())->toBeTrue();

        $github->seedWorkflowRun(fakeRun('5', ['conclusion' => WorkflowConclusion::Cancelled]));

        expect($actions->run(5)->wasCancelled())->toBeTrue()
            ->and($actions->runs()->get()->items)->toHaveCount(1);
    });
});

describe('jobs()', function (): void {
    it('keeps the latest attempt by default, every attempt or one attempt on request', function (): void {
        Git::fake()->fakeFor(ProviderName::Github)->seedWorkflowJobs(7, [
            fakeJob('a', '7', 1),
            fakeJob('b', '7', 2),
            fakeJob('c', '7', 2),
        ]);

        $actions = Git::github()->repo('acme/app')->actions();
        $ids = fn (array $jobs): array => array_map(fn (WorkflowJob $job): string => $job->id, $jobs);

        expect($ids($actions->jobs(7)->get()->items))->toBe(['b', 'c'])
            ->and($actions->jobs(7)->get()->total)->toBe(2)
            ->and($ids($actions->jobs('7')->allAttempts()->get()->items))->toBe(['a', 'b', 'c'])
            ->and($ids($actions->jobs(7)->attempt(1)->get()->items))->toBe(['a'])
            ->and($actions->jobs(7)->attempt(3)->get()->items)->toBe([])
            ->and($actions->jobs(8)->get()->items)->toBe([])
            ->and($actions->jobs(7)->perPage(1)->get()->total)->toBe(2);
    });
});

describe('dispatch()', function (): void {
    it('creates the queued run it reports, with the next id', function (): void {
        Carbon::setTestNow('2026-03-01T12:00:00Z');
        Git::fake()->fakeFor(ProviderName::Github)->seedWorkflowRuns([fakeRun('41'), fakeRun('7')]);

        $dispatched = Git::github()->repo('acme/app')->actions()->dispatch('deploy.yml', 'refs/heads/main', ['request_id' => 'r-1']);
        $run = Git::github()->repo('acme/app')->actions()->run((string) $dispatched->runId);

        expect($dispatched->runId)->toBe('42')
            ->and($dispatched->apiUrl)->toBe('https://fake/repos/acme/app/actions/runs/42')
            ->and($dispatched->url)->toBe('https://fake/acme/app/actions/runs/42')
            ->and($dispatched->dispatchedAt->toIso8601ZuluString())->toBe('2026-03-01T12:00:00Z')
            ->and($run->status)->toBe(WorkflowStatus::Queued)
            ->and($run->conclusion)->toBeNull()
            ->and($run->event)->toBe('workflow_dispatch')
            ->and($run->path)->toBe('.github/workflows/deploy.yml')
            ->and($run->workflowId)->toBe('100')
            ->and($run->headBranch)->toBe('main')
            ->and($run->displayTitle)->toBe('deploy.yml')
            ->and($run->createdAt->toIso8601ZuluString())->toBe('2026-03-01T12:00:00Z');

        Carbon::setTestNow();
    });

    it('starts the ids at 1 and leaves an unknown workflow id empty', function (): void {
        Git::fake();

        $dispatched = Git::github()->repo('acme/app')->actions()->dispatch('ci.yml', 'develop');

        expect($dispatched->runId)->toBe('1')
            ->and(Git::github()->repo('acme/app')->actions()->run(1))
            ->workflowId->toBe('')
            ->headBranch->toBe('develop');
    });

    it('puts the seeded branch head on the run, or a stand-in sha', function (): void {
        Git::fake()->fakeFor(ProviderName::Github)->seedBranch(new Branch(ProviderName::Github, 'main', str_repeat('c', 40)));

        $actions = Git::github()->repo('acme/app')->actions();

        expect($actions->run((string) $actions->dispatch('deploy.yml', 'refs/heads/main')->runId)->headSha)->toBe(str_repeat('c', 40))
            ->and($actions->run((string) $actions->dispatch('deploy.yml', 'develop')->runId))
            ->headSha->toBe('fake-sha')
            ->runNumber->toBe(2);
    });

    it('creates a run for a workflow named by id, borrowing a seeded run\'s path', function (): void {
        Git::fake()->fakeFor(ProviderName::Github)->seedWorkflowRuns([fakeRun('3')]);

        $runId = Git::github()->repo('acme/app')->actions()->dispatch('100', 'main')->runId;

        expect(Git::github()->repo('acme/app')->actions()->run((string) $runId))
            ->workflowId->toBe('100')
            ->path->toBe('.github/workflows/deploy.yml');
    });

    it('answers without a run id after seedDispatchWithoutRunDetails(), yet still creates the run', function (): void {
        // GitHub Enterprise Server's 204: the host finds the run with a runs() query.
        Git::fake()->fakeFor(ProviderName::Github)->seedDispatchWithoutRunDetails();

        $dispatched = Git::github()->repo('acme/app')->actions()->dispatch('deploy.yml', 'main');

        $found = Git::github()->repo('acme/app')->actions()->runs('deploy.yml')
            ->event('workflow_dispatch')
            ->branch('main')
            ->createdAfter($dispatched->dispatchedAt->subMinute())
            ->first();

        expect($dispatched->runId)->toBeNull()
            ->and($dispatched->url)->toBeNull()
            ->and($found?->status)->toBe(WorkflowStatus::Queued);
    });

    it('drives the whole dispatch, find, poll and cancel flow', function (): void {
        $fake = Git::fake();
        $github = $fake->fakeFor(ProviderName::Github);
        $actions = Git::github()->repo('acme/app')->actions();

        $dispatched = $actions->dispatch('deploy.yml', 'refs/heads/main', ['request_id' => 'r-1']);
        $found = $actions->runs('deploy.yml')->event('workflow_dispatch')->branch('main')->status(WorkflowStatus::Queued)->first();

        expect($found?->id)->toBe($dispatched->runId)
            ->and($actions->run((string) $dispatched->runId)->isActive())->toBeTrue()
            ->and($actions->cancel((string) $dispatched->runId))->toBeTrue();

        // GitHub cancels asynchronously: the test seeds the state the next poll reads.
        $github->seedWorkflowRun(fakeRun((string) $dispatched->runId, ['conclusion' => WorkflowConclusion::Cancelled]));

        expect($actions->run((string) $dispatched->runId)->wasCancelled())->toBeTrue()
            ->and($actions->cancel((string) $dispatched->runId))->toBeFalse();

        $fake->assertWorkflowDispatched('deploy.yml', 'refs/heads/main', ['request_id' => 'r-1'], 'acme/app');
        $fake->assertWorkflowRunCancelled((int) $dispatched->runId, 'acme/app');
        $fake->assertSent(ProviderName::Github, 'dispatchWorkflow', fn (string $path, NewWorkflowDispatch $d): bool => $d->inputs['request_id'] === 'r-1');
    });

    it('needs a credential to dispatch or cancel, as production does', function (): void {
        config()->set('git.providers.github.token', null);

        $fake = Git::fake();

        expect(fn () => Git::github()->repo('acme/app')->actions()->dispatch('deploy.yml', 'main'))->toThrow(InvalidCredentialsException::class)
            ->and(fn () => Git::github()->repo('acme/app')->actions()->cancel(1))->toThrow(InvalidCredentialsException::class);

        $fake->assertNoWorkflowDispatched();
        $fake->assertNoWorkflowRunCancelled();
    });

    it('refuses actions on a fake gitlab or bitbucket, as production does', function (): void {
        $fake = Git::fake();

        expect(fn () => Git::gitlab()->repo('g/p')->actions()->dispatch('deploy.yml', 'main'))->toThrow(FeatureNotSupportedException::class)
            ->and(fn () => Git::bitbucket()->repo('w/r')->actions()->runs())->toThrow(FeatureNotSupportedException::class)
            ->and(fn () => Git::gitlab()->repo('g/p')->actions()->cancel(1))->toThrow(FeatureNotSupportedException::class);

        $fake->assertNoWorkflowDispatched();
    });
});

it('cancels a run that is not completed, and refuses one that is, keeping the seeds as they were', function (): void {
    Git::fake()->fakeFor(ProviderName::Github)->seedWorkflowRuns([
        fakeRun('1'),
        fakeRun('2', ['status' => WorkflowStatus::InProgress, 'conclusion' => null]),
    ]);

    $actions = Git::github()->repo('acme/app')->actions();

    expect($actions->cancel(1))->toBeFalse()
        ->and($actions->cancel(2))->toBeTrue()
        ->and($actions->cancel(3))->toBeTrue()
        ->and($actions->run(2)->status)->toBe(WorkflowStatus::InProgress);
});

describe('the assertions', function (): void {
    it('asserts a dispatched workflow, narrowing by ref, exact inputs and repository', function (): void {
        $fake = Git::fake();

        Git::github()->repo('acme/app')->actions()->dispatch('deploy.yml', 'main', ['request_id' => 'r-1', 'dry' => true]);

        $fake->assertWorkflowDispatched('deploy.yml');
        $fake->assertWorkflowDispatched('deploy.yml', 'main');
        $fake->assertWorkflowDispatched('deploy.yml', inputs: ['dry' => true, 'request_id' => 'r-1']);
        $fake->assertWorkflowDispatched('deploy.yml', repository: 'acme/app');
        Git::assertWorkflowDispatched('deploy.yml', 'main', ['request_id' => 'r-1', 'dry' => true], 'acme/app');

        expect(fn () => $fake->assertWorkflowDispatched('ci.yml'))->toThrow(AssertionFailedError::class, 'ci.yml')
            ->and(fn () => $fake->assertWorkflowDispatched('deploy.yml', 'develop'))->toThrow(AssertionFailedError::class)
            // Exact, not a subset: a missing or extra input is a different dispatch.
            ->and(fn () => $fake->assertWorkflowDispatched('deploy.yml', inputs: ['request_id' => 'r-1']))->toThrow(AssertionFailedError::class)
            ->and(fn () => $fake->assertWorkflowDispatched('deploy.yml', inputs: ['request_id' => 'r-1', 'dry' => 'true']))->toThrow(AssertionFailedError::class)
            ->and(fn () => $fake->assertWorkflowDispatched('deploy.yml', repository: 'acme/other'))->toThrow(AssertionFailedError::class);
    });

    it('asserts that no workflow was dispatched', function (): void {
        $fake = Git::fake();

        $fake->assertNoWorkflowDispatched();
        Git::assertNoWorkflowDispatched();

        Git::github()->repo('acme/app')->actions()->dispatch('deploy.yml', 'main');

        expect(fn () => $fake->assertNoWorkflowDispatched())->toThrow(AssertionFailedError::class);
    });

    it('asserts a cancelled run, by id as int or string, optionally in a repository', function (): void {
        $fake = Git::fake();

        Git::github()->repo('acme/app')->actions()->cancel(7);

        $fake->assertWorkflowRunCancelled(7);
        $fake->assertWorkflowRunCancelled('7', 'acme/app');
        Git::assertWorkflowRunCancelled(7);

        expect(fn () => $fake->assertWorkflowRunCancelled(8))->toThrow(AssertionFailedError::class, '8')
            ->and(fn () => $fake->assertWorkflowRunCancelled(7, 'acme/other'))->toThrow(AssertionFailedError::class);
    });

    it('asserts that no run was cancelled', function (): void {
        $fake = Git::fake();

        $fake->assertNoWorkflowRunCancelled();
        Git::assertNoWorkflowRunCancelled();

        Git::github()->repo('acme/app')->actions()->cancel(7);

        expect(fn () => $fake->assertNoWorkflowRunCancelled())->toThrow(AssertionFailedError::class);
    });
});
