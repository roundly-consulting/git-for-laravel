<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Handles;

use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use RoundlyConsulting\Git\Dto\DispatchedWorkflow;
use RoundlyConsulting\Git\Dto\Input\NewWorkflowDispatch;
use RoundlyConsulting\Git\Dto\WorkflowRun;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Interfaces\Provider;
use RoundlyConsulting\Git\Query\WorkflowJobQuery;
use RoundlyConsulting\Git\Query\WorkflowRunQuery;

/**
 * The GitHub Actions of one repository — `Git::github()->repo('acme/app')->actions()`.
 *
 * Built by {@see RepositoryHandle::actions()}, so it is always scoped to the repository it
 * came from. Every workflow and run id is checked here, before the driver is called: an id
 * lands in a URL, and `../7` or `7/jobs` would address another resource. Forges without
 * Actions (GitLab, Bitbucket) answer `FeatureNotSupportedException`.
 *
 * Permissions for a scoped `GithubAppToken`: `actions: write` to dispatch and cancel,
 * `actions: read` for runs, one run and jobs.
 */
final readonly class ActionsHandle
{
    /**
     * @throws OutOfScopeException when the path could step outside the repository
     */
    public function __construct(
        private Provider $provider,
        private string $path,
    ) {
        PathGuard::repository($path);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Start a workflow (`workflow_dispatch`) on a ref. Never retried: a lost answer followed
     * by a retry would start a second run.
     *
     * The answer carries the run GitHub started (`runId`) on github.com and GHE.com; GitHub
     * Enterprise Server answers without it — find the run with {@see runs()} from
     * `dispatchedAt`, matching a marker you put in the workflow's `run-name`.
     *
     * @param  string  $workflow  the workflow file (`deploy.yml`) or its numeric id
     * @param  string  $ref  sent as given: `main` and `refs/heads/main` both work
     * @param  array<string, string|int|float|bool>  $inputs
     *
     * @throws OutOfScopeException when the workflow or ref could address something else
     * @throws InvalidArgumentException when an input is not a named scalar
     * @throws InvalidCredentialsException when there is no credential
     * @throws FeatureNotSupportedException on a forge without Actions
     */
    public function dispatch(string $workflow, string $ref, array $inputs = []): DispatchedWorkflow
    {
        return $this->provider->dispatchWorkflow($this->path, new NewWorkflowDispatch($workflow, $ref, $inputs));
    }

    /**
     * Runs, newest first — of one workflow (file or id), or of the whole repository.
     *
     * @throws OutOfScopeException when the workflow is not one yaml file or a numeric id
     */
    public function runs(?string $workflow = null): WorkflowRunQuery
    {
        return $this->provider->workflowRuns($this->path, $workflow === null ? null : PathGuard::workflow($workflow));
    }

    /**
     * One run, as it is now. Poll it with `GIT_CACHE_ENABLED=true` and an unchanged run
     * comes back as a `304`, which GitHub does not count against the primary rate limit.
     *
     * @throws OutOfScopeException when the id is not numeric
     */
    public function run(int|string $id): WorkflowRun
    {
        return $this->provider->workflowRun($this->path, $this->runId($id));
    }

    /**
     * The jobs of one run — of its latest attempt unless `->allAttempts()` / `->attempt($n)`.
     *
     * @throws OutOfScopeException when the id is not numeric
     */
    public function jobs(int|string $runId): WorkflowJobQuery
    {
        return $this->provider->workflowJobs($this->path, $this->runId($runId));
    }

    /**
     * Ask GitHub to cancel a run: `true` when it accepted (it cancels asynchronously — read
     * {@see run()} for the outcome), `false` when the run had already finished (`409`).
     *
     * @throws OutOfScopeException when the id is not numeric
     * @throws InvalidCredentialsException when there is no credential
     * @throws RequestException for any other refusal
     */
    public function cancel(int|string $runId): bool
    {
        return $this->provider->cancelWorkflowRun($this->path, $this->runId($runId));
    }

    private function runId(int|string $id): string
    {
        return PathGuard::numeric('workflow run id', (string) $id);
    }
}
