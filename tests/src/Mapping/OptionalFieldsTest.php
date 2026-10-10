<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\WebhookEvent;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ResourceState;
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\Mapping\BitbucketMapper;
use RoundlyConsulting\Git\Mapping\GithubMapper;
use RoundlyConsulting\Git\Mapping\GitlabMapper;

/*
 * The head commit and head repository of a pull request, and a repository's visibility,
 * language and web page — read off `raw()` until now. All optional: null where a forge
 * does not say.
 */

/** @return array<string, mixed> */
function githubPullRequestPayload(array $head = []): array
{
    return [
        'id' => 1, 'number' => 7, 'title' => 'Add CI', 'state' => 'open', 'created_at' => '2026-01-01T00:00:00Z',
        'head' => array_replace(['ref' => 'feature', 'sha' => str_repeat('a', 40), 'repo' => ['full_name' => 'fork/app']], $head),
        'base' => ['ref' => 'main'],
    ];
}

describe('pull requests', function (): void {
    it('maps the github head sha and head repository', function (): void {
        $pr = (new GithubMapper)->pullRequest(githubPullRequestPayload());

        expect($pr->headSha)->toBe(str_repeat('a', 40))
            ->and($pr->headRepository)->toBe('fork/app');
    });

    it('reads a deleted github fork as no head repository', function (): void {
        expect((new GithubMapper)->pullRequest(githubPullRequestPayload(['repo' => null]))->headRepository)->toBeNull();
    });

    it('maps the gitlab head sha from rest or a hook, but no head repository', function (): void {
        $rest = (new GitlabMapper)->pullRequest([
            'id' => 1, 'iid' => 4, 'title' => 'MR', 'state' => 'opened', 'sha' => str_repeat('b', 40),
            'source_project_id' => 9, 'created_at' => '2026-01-01T00:00:00Z',
        ]);
        $hook = (new GitlabMapper)->pullRequest([
            'id' => 1, 'iid' => 4, 'title' => 'MR', 'state' => 'opened', 'last_commit' => ['id' => str_repeat('c', 40)],
            'created_at' => '2026-01-01T00:00:00Z',
        ]);

        expect($rest->headSha)->toBe(str_repeat('b', 40))
            ->and($rest->headRepository)->toBeNull()
            ->and($hook->headSha)->toBe(str_repeat('c', 40));
    });

    it('maps the bitbucket head commit (short) and source repository', function (): void {
        $pr = (new BitbucketMapper)->pullRequest([
            'id' => 5, 'title' => 'PR', 'state' => 'OPEN', 'created_on' => '2026-01-01T00:00:00Z',
            'source' => ['branch' => ['name' => 'f'], 'commit' => ['hash' => 'abc123def456'], 'repository' => ['full_name' => 'fork/app']],
            'destination' => ['branch' => ['name' => 'main']],
        ]);

        expect($pr->headSha)->toBe('abc123def456')
            ->and($pr->headRepository)->toBe('fork/app');
    });

    it('leaves the new fields null when a payload does not carry them', function (): void {
        $pr = (new GithubMapper)->pullRequest(['head' => ['ref' => 'f']] + githubPullRequestPayload());

        expect($pr->headSha)->toBeNull()
            ->and($pr->headRepository)->toBeNull();
    });

    it('reaches the github pull request webhook', function (): void {
        $payload = webhookFixture('github', 'pull_request');
        $payload['pull_request']['head'] = ['ref' => 'feature', 'sha' => str_repeat('d', 40), 'repo' => ['full_name' => 'octocat/Hello-World']];

        $pr = (new WebhookEvent(ProviderName::Github, 'pull_request', $payload))->pullRequest();

        expect($pr?->headSha)->toBe(str_repeat('d', 40))
            ->and($pr?->headRepository)->toBe('octocat/Hello-World');
    });

    it('reaches the gitlab merge request webhook through its last commit', function (): void {
        $payload = webhookFixture('gitlab', 'merge_request');
        $payload['object_attributes']['last_commit'] = ['id' => str_repeat('e', 40)];

        expect((new WebhookEvent(ProviderName::Gitlab, 'Merge Request Hook', $payload))->pullRequest()?->headSha)->toBe(str_repeat('e', 40));
    });

    it('still builds a pull request from the original positional arguments', function (): void {
        $pr = new PullRequest(ProviderName::Github, '1', 7, 't', null, ResourceState::Open, 'f', 'main', null, null, Carbon::now(), false, ['k' => 'v']);

        expect($pr->raw())->toBe(['k' => 'v'])
            ->and($pr->headSha)->toBeNull()
            ->and($pr->headRepository)->toBeNull()
            ->and($pr->toArray())->toHaveKeys(['headSha', 'headRepository']);
    });

    it('carries the new fields through the fake\'s close', function (): void {
        fakeCredentials();

        Git::fake()->fakeFor(ProviderName::Github)->seedPullRequest(new PullRequest(
            ProviderName::Github, '1', 7, 't', null, ResourceState::Open, 'f', 'main', null, null, Carbon::now(),
            headSha: str_repeat('f', 40), headRepository: 'fork/app',
        ));

        expect(Git::github()->repo('acme/app')->pullRequest(7)->close())
            ->state->toBe(ResourceState::Closed)
            ->headSha->toBe(str_repeat('f', 40))
            ->headRepository->toBe('fork/app');
    });
});

describe('repositories', function (): void {
    it('maps github visibility, language and web page', function (): void {
        $repo = (new GithubMapper)->repository(['language' => 'Ruby'] + snapshotData('github/repository'));
        $private = (new GithubMapper)->repository(['private' => true] + snapshotData('github/repository'));

        expect($repo->private)->toBeFalse()
            ->and($repo->language)->toBe('Ruby')
            ->and($repo->webUrl)->toBe('https://github.com/octocat/Hello-World')
            ->and($private->private)->toBeTrue()
            ->and($private->language)->toBeNull();
    });

    it('maps gitlab visibility, counting internal as private, and its web page', function (string $visibility, bool $private): void {
        $repo = (new GitlabMapper)->repository(['visibility' => $visibility] + snapshotData('gitlab/repository'));

        expect($repo->private)->toBe($private)
            ->and($repo->language)->toBeNull()
            ->and($repo->webUrl)->toBe('https://gitlab.example.com/diaspora/diaspora-client');
    })->with([
        'public' => ['public', false],
        'internal' => ['internal', true],
        'private' => ['private', true],
    ]);

    it('leaves gitlab visibility unknown when the payload does not say', function (): void {
        $payload = snapshotData('gitlab/repository');
        unset($payload['visibility']);

        expect((new GitlabMapper)->repository($payload)->private)->toBeNull();
    });

    it('maps bitbucket visibility, language and web page, reading an empty language as none', function (): void {
        $payload = ['language' => 'php'] + snapshotData('bitbucket/repository');
        $repo = (new BitbucketMapper)->repository($payload);

        expect($repo->private)->toBeTrue()
            ->and($repo->language)->toBe('php')
            ->and($repo->webUrl)->toBe($payload['links']['html']['href'])
            ->and((new BitbucketMapper)->repository(['language' => ''] + $payload)->language)->toBeNull();
    });

    it('reaches the github webhook repository', function (): void {
        $payload = webhookFixture('github', 'push');
        $payload['repository'] += ['private' => true, 'language' => 'PHP', 'html_url' => 'https://github.com/octocat/Hello-World'];

        $repo = (new WebhookEvent(ProviderName::Github, 'push', $payload))->repository();

        expect($repo?->private)->toBeTrue()
            ->and($repo?->language)->toBe('PHP')
            ->and($repo?->webUrl)->toBe('https://github.com/octocat/Hello-World');
    });

    it('reads a gitlab hook project\'s visibility level', function (int $level, bool $private): void {
        // Hooks carry `visibility_level` (0 private, 10 internal, 20 public), not `visibility`.
        $payload = webhookFixture('gitlab', 'push');
        $payload['project'] += ['visibility_level' => $level, 'web_url' => 'https://gitlab.example.com/g/p'];

        $repo = (new WebhookEvent(ProviderName::Gitlab, 'Push Hook', $payload))->repository();

        expect($repo?->private)->toBe($private)
            ->and($repo?->webUrl)->toBe('https://gitlab.example.com/g/p');
    })->with([
        'private' => [0, true],
        'internal' => [10, true],
        'public' => [20, false],
    ]);

    it('still builds a repository from the original positional arguments', function (): void {
        $repo = new Repository(ProviderName::Github, '1', 'o/r', 'r', null, 'main', new Owner('1', 'o', null), Carbon::now(), Carbon::now(), ['k' => 'v']);

        expect($repo->raw())->toBe(['k' => 'v'])
            ->and($repo->private)->toBeNull()
            ->and($repo->language)->toBeNull()
            ->and($repo->webUrl)->toBeNull()
            ->and($repo->toArray())->toHaveKeys(['private', 'language', 'webUrl']);
    });

    it('reports the visibility the fake created a repository with', function (): void {
        fakeCredentials();
        Git::fake();

        expect(Git::github()->createRepository(new NewRepository('widget', private: true))->private)->toBeTrue();
    });
});
