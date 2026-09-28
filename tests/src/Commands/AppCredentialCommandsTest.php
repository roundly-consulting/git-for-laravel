<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * With a GitHub App configured, `Git::github()` authenticates as an INSTALLATION, and an
 * installation token has no user behind it: `/user` and `/user/repos` answer 403. The
 * commands must reach the installation's own endpoints instead.
 */

beforeEach(function (): void {
    [$privateKey] = generateRsaKeypair();

    config()->set('git.providers.github.token', null);
    config()->set('git.providers.github.app.id', '123');
    config()->set('git.providers.github.app.installation_id', '999');
    config()->set('git.providers.github.app.private_key', $privateKey);
});

it('lists the installation repositories for an app credential', function (): void {
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601String()]),
        '*/installation/repositories*' => Http::response(['total_count' => 1, 'repositories' => [snapshotData('github/repository')]]),
        '*/user/repos*' => Http::response(['message' => 'Resource not accessible by integration'], 403),
    ]);

    $this->artisan('git:repos github')
        ->expectsTable(
            ['Name', 'Path', 'Default branch'],
            [['Hello-World', 'octocat/Hello-World', 'master']],
        )
        ->assertExitCode(0);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/user/repos'));
});

it('reads the rate limit through an installation endpoint for an app credential', function (): void {
    Http::fake([
        '*/app/installations/999/access_tokens' => Http::response(['token' => 'ghs_x', 'expires_at' => now()->addHour()->toIso8601String()]),
        '*/installation/repositories*' => Http::response(['total_count' => 0, 'repositories' => []], 200, [
            'X-RateLimit-Limit' => '5000',
            'X-RateLimit-Remaining' => '4990',
            'X-RateLimit-Used' => '10',
        ]),
        '*/user' => Http::response(['message' => 'Resource not accessible by integration'], 403),
    ]);

    $this->artisan('git:rate-limit github')
        ->expectsOutputToContain('4990')
        ->assertExitCode(0);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/user'));
});
