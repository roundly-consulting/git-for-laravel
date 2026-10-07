<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('git.providers.github.token', 'ghp_test');
});

it('registers a webhook with an explicit url', function () {
    Http::fake([
        '*/repos/acme/api/hooks*' => Http::sequence()
            ->push([])
            ->push(['id' => 11, 'config' => ['url' => 'https://app.test/hook'], 'events' => ['push'], 'active' => true]),
    ]);

    $this->artisan('git:webhook github acme/api --url=https://app.test/hook')
        ->expectsOutputToContain('registered')
        ->assertExitCode(0);
});

it('lists existing webhooks', function () {
    Http::fake([
        '*/repos/acme/api/hooks*' => Http::response([
            ['id' => 11, 'config' => ['url' => 'https://app.test/hook'], 'events' => ['push'], 'active' => true],
        ]),
    ]);

    $this->artisan('git:webhook github acme/api --list')->assertExitCode(0);
});

it('deletes a webhook by id', function () {
    Http::fake(['*/repos/acme/api/hooks/11' => Http::response([], 204)]);

    $this->artisan('git:webhook github acme/api --delete=11')
        ->expectsOutputToContain('Deleted')
        ->assertExitCode(0);
});

it('fails with a friendly message when no credentials are set', function () {
    config()->set('git.providers.github.token', null);

    $this->artisan('git:webhook github acme/api --url=https://app.test/hook')
        ->expectsOutputToContain('No credentials')
        ->assertExitCode(1);
});

it('rejects an unknown provider', function () {
    $this->artisan('git:webhook unknown acme/api')->assertExitCode(1);
});
