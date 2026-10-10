<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Dto\JobStep;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\WorkflowJob;
use RoundlyConsulting\Git\Dto\WorkflowRun;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\WorkflowConclusion;
use RoundlyConsulting\Git\Enums\WorkflowStatus;
use RoundlyConsulting\Git\Mapping\GithubMapper;

/*
 * GitHub Actions runs, jobs and steps, mapped from GitHub's documented example payloads.
 * Status and conclusion are typed: a value GitHub adds later reads as Unknown, which never
 * counts as completed or successful.
 */

function workflowMapper(): GithubMapper
{
    return resolve(GithubMapper::class);
}

/** @return array<string, mixed> */
function workflowRunFixture(array $overrides = []): array
{
    return array_replace(snapshotData('github/workflow-run'), $overrides);
}

it('maps the documented workflow run', function (): void {
    $run = workflowMapper()->workflowRun(snapshotData('github/workflow-run'));

    expect($run)->toBeInstanceOf(WorkflowRun::class)
        ->provider->toBe(ProviderName::Github)
        ->id->toBe('30433642')
        ->workflowId->toBe('159038')
        ->name->toBe('Build')
        ->displayTitle->toBe('Update README.md')
        ->status->toBe(WorkflowStatus::Queued)
        ->conclusion->toBeNull()
        ->event->toBe('push')
        ->path->toBe('.github/workflows/build.yml@main')
        ->headBranch->toBe('master')
        ->headSha->toBe('acb5820ced9479c074f688cc328bf03f341a511d')
        ->headRepository->toBe('octo-org/octo-repo')
        ->runNumber->toBe(562)
        ->runAttempt->toBe(1)
        ->url->toBe('https://github.com/octo-org/octo-repo/actions/runs/30433642')
        ->and($run->actor)->toBeInstanceOf(Owner::class)
        ->and($run->actor?->name)->toBe('octocat')
        ->and($run->triggeringActor?->id)->toBe('1')
        ->and($run->createdAt->toIso8601ZuluString())->toBe('2020-01-22T19:33:08Z')
        ->and($run->updatedAt->toIso8601ZuluString())->toBe('2020-01-22T19:33:08Z')
        ->and($run->runStartedAt?->toIso8601ZuluString())->toBe('2020-01-22T19:33:08Z')
        ->and($run->raw()['check_suite_id'])->toBe(42)
        ->and($run->toArray())->toMatchArray(['status' => 'queued', 'conclusion' => null])->not->toHaveKey('raw');
});

it('maps the documented job with its steps', function (): void {
    $job = workflowMapper()->workflowJob(snapshotData('github/jobs')['jobs'][0]);

    expect($job)->toBeInstanceOf(WorkflowJob::class)
        ->provider->toBe(ProviderName::Github)
        ->id->toBe('399444496')
        ->runId->toBe('29679449')
        ->runAttempt->toBe(1)
        ->name->toBe('build')
        ->workflowName->toBe('CI')
        ->status->toBe(WorkflowStatus::Completed)
        ->conclusion->toBe(WorkflowConclusion::Success)
        ->headSha->toBe('f83a356604ae3c5d03e1b46ef4d1ca77d64a90b0')
        ->headBranch->toBe('main')
        ->runnerName->toBe('my runner')
        ->labels->toBe(['self-hosted', 'foo', 'bar'])
        ->url->toBe('https://github.com/octo-org/octo-repo/runs/399444496')
        ->and($job->startedAt?->toIso8601ZuluString())->toBe('2020-01-20T17:42:40Z')
        ->and($job->completedAt?->toIso8601ZuluString())->toBe('2020-01-20T17:44:39Z')
        ->and($job->steps)->toHaveCount(10)
        ->and($job->steps[0])->toBeInstanceOf(JobStep::class)
        ->and($job->steps[0]->number)->toBe(1)
        ->and($job->steps[0]->name)->toBe('Set up job')
        ->and($job->steps[0]->status)->toBe(WorkflowStatus::Completed)
        ->and($job->steps[0]->conclusion)->toBe(WorkflowConclusion::Success)
        ->and($job->steps[0]->startedAt?->toIso8601ZuluString())->toBe('2020-01-20T17:42:40Z')
        ->and($job->step('Deploy to Heroku')?->number)->toBe(8)
        ->and($job->step('Nope'))->toBeNull()
        ->and($job->succeeded())->toBeTrue()
        ->and($job->raw()['runner_group_name'])->toBe('my runner group')
        ->and($job->toArray()['steps'][0])->toMatchArray(['name' => 'Set up job', 'status' => 'completed']);
});

it('reads a status or conclusion github adds later as unknown, and a null status as unknown too', function (): void {
    $run = workflowMapper()->workflowRun(workflowRunFixture(['status' => 'hibernating', 'conclusion' => 'vaporized']));
    $nullStatus = workflowMapper()->workflowRun(workflowRunFixture(['status' => null]));

    expect($run->status)->toBe(WorkflowStatus::Unknown)
        ->and($run->conclusion)->toBe(WorkflowConclusion::Unknown)
        ->and($run->isCompleted())->toBeFalse()
        ->and($run->isActive())->toBeTrue()
        ->and($run->succeeded())->toBeFalse()
        ->and($nullStatus->status)->toBe(WorkflowStatus::Unknown)
        ->and($nullStatus->conclusion)->toBeNull();
});

it('defaults a missing attempt to 1 and a missing update time to the creation time', function (): void {
    $payload = workflowRunFixture(['head_repository' => null, 'actor' => null, 'run_started_at' => null]);
    unset($payload['run_attempt'], $payload['updated_at']);

    $run = workflowMapper()->workflowRun($payload);

    expect($run->runAttempt)->toBe(1)
        ->and($run->updatedAt->equalTo($run->createdAt))->toBeTrue()
        ->and($run->headRepository)->toBeNull()
        ->and($run->actor)->toBeNull()
        ->and($run->runStartedAt)->toBeNull();
});

it('refuses a run or job payload without a usable id, naming the field', function (Closure $map, string $field): void {
    expect($map)->toThrow(InvalidArgumentException::class, "[{$field}]");
})->with([
    'run id' => [fn () => workflowMapper()->workflowRun(array_diff_key(workflowRunFixture(), ['id' => true])), 'id'],
    'run workflow id' => [fn () => workflowMapper()->workflowRun(workflowRunFixture(['workflow_id' => ''])), 'workflow_id'],
    'run creation time' => [fn () => workflowMapper()->workflowRun(workflowRunFixture(['created_at' => null])), 'created_at'],
    'job id' => [fn () => workflowMapper()->workflowJob(array_diff_key(snapshotData('github/jobs')['jobs'][0], ['id' => true])), 'id'],
    'job run id' => [fn () => workflowMapper()->workflowJob(['run_id' => null] + snapshotData('github/jobs')['jobs'][0]), 'run_id'],
]);

it('answers the run helpers truthfully', function (string $status, ?string $conclusion, bool $completed, bool $active, bool $succeeded, bool $cancelled): void {
    $run = workflowMapper()->workflowRun(workflowRunFixture(['status' => $status, 'conclusion' => $conclusion]));

    expect($run->isCompleted())->toBe($completed)
        ->and($run->isActive())->toBe($active)
        ->and($run->succeeded())->toBe($succeeded)
        ->and($run->wasCancelled())->toBe($cancelled);
})->with([
    'completed + success' => ['completed', 'success', true, false, true, false],
    'completed + cancelled' => ['completed', 'cancelled', true, false, false, true],
    'completed + failure' => ['completed', 'failure', true, false, false, false],
    'in progress' => ['in_progress', null, false, true, false, false],
    'queued' => ['queued', null, false, true, false, false],
    'unknown is never completed' => ['brand_new', 'success', false, true, false, false],
]);

it('answers the job and step helpers truthfully', function (): void {
    $failed = workflowMapper()->workflowJob(['conclusion' => 'failure'] + snapshotData('github/jobs')['jobs'][0]);
    $running = workflowMapper()->workflowJob(['status' => 'in_progress', 'conclusion' => null, 'completed_at' => null] + snapshotData('github/jobs')['jobs'][0]);

    expect($failed->succeeded())->toBeFalse()
        ->and($running->succeeded())->toBeFalse()
        ->and($running->completedAt)->toBeNull()
        ->and(new JobStep(1, 'Build', WorkflowStatus::Completed, WorkflowConclusion::Success))->succeeded()->toBeTrue()
        ->and(new JobStep(2, 'Test', WorkflowStatus::InProgress))->succeeded()->toBeFalse()
        ->and(new JobStep(3, 'Lint', WorkflowStatus::Completed, WorkflowConclusion::Skipped))->succeeded()->toBeFalse();
});

it('builds runs and jobs by hand with sensible defaults, for seeding the fake', function (): void {
    $run = new WorkflowRun(
        provider: ProviderName::Github,
        id: '7',
        workflowId: '1',
        displayTitle: 'Deploy',
        status: WorkflowStatus::Completed,
        event: 'workflow_dispatch',
        path: '.github/workflows/deploy.yml',
        headSha: str_repeat('a', 40),
        createdAt: Carbon::parse('2026-01-01'),
        updatedAt: Carbon::parse('2026-01-01'),
        conclusion: WorkflowConclusion::Success,
    );

    $job = new WorkflowJob(ProviderName::Github, '9', '7', 'deploy', WorkflowStatus::Queued, str_repeat('a', 40));

    expect($run->runAttempt)->toBe(1)
        ->and($run->runNumber)->toBe(1)
        ->and($run->succeeded())->toBeTrue()
        ->and($run->raw())->toBe([])
        ->and($job->runAttempt)->toBe(1)
        ->and($job->steps)->toBe([])
        ->and($job->labels)->toBe([]);
});

it('exposes the workflow statuses and conclusions through the enum helpers', function (): void {
    expect(WorkflowStatus::values()->all())->toBe(['requested', 'queued', 'pending', 'waiting', 'in_progress', 'completed', 'unknown'])
        ->and(WorkflowConclusion::values()->all())->toBe(['success', 'failure', 'neutral', 'cancelled', 'skipped', 'timed_out', 'action_required', 'stale', 'startup_failure', 'unknown']);
});
