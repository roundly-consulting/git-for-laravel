<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('git.providers.github.token', 'ghp_test');
});

it('lists repositories as a table', function () {
    Http::fake(['*/user/repos*' => Http::response([snapshotData('github/repository')])]);

    $this->artisan('git:repos github')
        ->expectsTable(
            ['Name', 'Path', 'Default branch'],
            [['Hello-World', 'octocat/Hello-World', 'master']],
        )
        ->assertExitCode(0);
});

it('lists repositories as json', function () {
    Http::fake(['*/user/repos*' => Http::response([snapshotData('github/repository')])]);

    $this->artisan('git:repos github --json')->assertExitCode(0);
});

it('fails with a friendly message when no credentials are set', function () {
    config()->set('git.providers.github.token', null);

    $this->artisan('git:repos github')
        ->expectsOutputToContain('No credentials')
        ->assertExitCode(1);
});

it('rejects an unknown provider', function () {
    $this->artisan('git:repos unknown')->assertExitCode(1);
});

it('prints the rate limit status', function () {
    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'o'], 200, [
        'X-RateLimit-Limit' => '5000',
        'X-RateLimit-Remaining' => '4000',
        'X-RateLimit-Used' => '1000',
        'X-RateLimit-Reset' => (string) Carbon::parse('2030-01-01')->timestamp,
    ])]);

    $this->artisan('git:rate-limit github')->assertExitCode(0);
});

it('warns when no rate limit info is available', function () {
    Http::fake(['*/user' => Http::response(['id' => 1, 'login' => 'o'])]);

    $this->artisan('git:rate-limit github')
        ->expectsOutputToContain('No rate-limit information')
        ->assertExitCode(0);
});

it('lists commits as a table', function () {
    Http::fake(['*/repos/o/r/commits*' => Http::response([snapshotData('github/commit')])]);

    $this->artisan('git:commits github o/r --branch=main')->assertExitCode(0);
});

it('fails commits command for unknown provider and missing creds', function () {
    config()->set('git.providers.gitlab.token', null);

    $this->artisan('git:commits unknown o/r')->assertExitCode(1);
    $this->artisan('git:commits gitlab o/r')->assertExitCode(1);
    $this->artisan('git:rate-limit unknown')->assertExitCode(1);
});

it('fails rate-limit command when credentials missing', function () {
    config()->set('git.providers.gitlab.token', null);

    $this->artisan('git:rate-limit gitlab')->assertExitCode(1);
});
