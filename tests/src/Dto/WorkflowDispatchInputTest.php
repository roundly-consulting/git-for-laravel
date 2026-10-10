<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Dto\DispatchedWorkflow;
use RoundlyConsulting\Git\Dto\Input\NewWorkflowDispatch;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Handles\PathGuard;

it('takes a workflow file or id, a ref and scalar inputs', function (): void {
    $dispatch = new NewWorkflowDispatch('deploy.yml', 'refs/heads/main', ['request_id' => 'r-1', 'dry' => true, 'n' => 3, 'f' => 1.5]);

    expect($dispatch->workflow)->toBe('deploy.yml')
        ->and($dispatch->ref)->toBe('refs/heads/main')
        ->and($dispatch->inputs)->toBe(['request_id' => 'r-1', 'dry' => true, 'n' => 3, 'f' => 1.5])
        ->and((new NewWorkflowDispatch('159038', 'main'))->inputs)->toBe([])
        ->and((new NewWorkflowDispatch('ci.yaml', 'v1.0.0'))->workflow)->toBe('ci.yaml');
});

it('refuses a workflow that is not one yaml file or a numeric id', function (string $workflow): void {
    expect(fn () => new NewWorkflowDispatch($workflow, 'main'))->toThrow(OutOfScopeException::class, 'workflow')
        ->and(fn () => PathGuard::workflow($workflow))->toThrow(OutOfScopeException::class);
})->with([
    'traversal' => ['../x.yml'],
    'a path' => ['a/b.yml'],
    'an encoded path' => ['a%2Fb.yml'],
    'not yaml' => ['x.txt'],
    'empty' => [''],
    'whitespace' => ['de ploy.yml'],
    'negative id' => ['-12'],
]);

it('refuses an empty or traversing ref', function (string $ref): void {
    expect(fn () => new NewWorkflowDispatch('deploy.yml', $ref))->toThrow(OutOfScopeException::class);
})->with(['', '../main', 'a b']);

it('refuses an input that is not a named scalar', function (array $inputs, string $message): void {
    expect(fn () => new NewWorkflowDispatch('deploy.yml', 'main', $inputs))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'nested array' => [['matrix' => ['a', 'b']], '[matrix]'],
    'null' => [['request_id' => null], '[request_id]'],
    'an object' => [['at' => new stdClass], '[at]'],
    'a list key' => [['positional'], 'name'],
]);

it('answers a numeric workflow id and a yaml file from the guard', function (): void {
    expect(PathGuard::workflow('159038'))->toBe('159038')
        ->and(PathGuard::workflow('deploy.yml'))->toBe('deploy.yml')
        ->and(PathGuard::workflow('build.yaml'))->toBe('build.yaml');
});

it('describes a dispatched workflow, with or without the run github started', function (): void {
    $at = Carbon::parse('2026-03-01T10:00:00Z');

    $found = new DispatchedWorkflow(ProviderName::Github, 'deploy.yml', 'main', '42', 'https://api.github.com/repos/o/r/actions/runs/42', 'https://github.com/o/r/actions/runs/42', $at, ['workflow_run_id' => 42]);
    $blind = new DispatchedWorkflow(ProviderName::Github, 'deploy.yml', 'main', null, null, null, $at);

    expect($found->runId)->toBe('42')
        ->and($found->raw())->toBe(['workflow_run_id' => 42])
        ->and($found->toArray())->toMatchArray(['runId' => '42', 'dispatchedAt' => $at->toIso8601String()])->not->toHaveKey('raw')
        ->and($blind->runId)->toBeNull()
        ->and($blind->apiUrl)->toBeNull()
        ->and($blind->url)->toBeNull();
});
