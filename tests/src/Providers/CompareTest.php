<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Comparison;
use RoundlyConsulting\Git\Enums\ComparisonStatus;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Facades\Git;

/*
 * `compare()` used to keep `ahead_by`, `behind_by` and `files` only. A forward-only guard
 * needs GitHub's own verdict (`status`), and a caller listing what changed needs the
 * commits and whether GitHub truncated them (`total_commits` against the ≤250 it sends).
 */

/** @return array<string, mixed> */
function compareCommitPayload(int $n): array
{
    return [
        'sha' => sprintf('%040d', $n),
        'html_url' => "https://github.com/o/r/commit/{$n}",
        'commit' => [
            'message' => "commit {$n}",
            'author' => ['name' => 'Mona', 'email' => 'mona@example.com', 'date' => '2026-01-01T00:00:00Z'],
        ],
        'author' => ['login' => 'mona', 'avatar_url' => 'https://avatars.test/mona'],
    ];
}

it('maps the documented compare payload', function (): void {
    Http::fake(['*/repos/octocat/Hello-World/compare/master...topic' => snapshot('github/compare')]);

    $comparison = github()->repo('octocat/Hello-World')->compare('master', 'topic');

    expect($comparison)
        ->toBeInstanceOf(Comparison::class)
        ->status->toBe(ComparisonStatus::Behind)
        ->aheadBy->toBe(1)
        ->behindBy->toBe(2)
        ->totalCommits->toBe(1)
        ->and($comparison->commits)->toHaveCount(1)
        ->and($comparison->commits[0])->toBeInstanceOf(Commit::class)
        ->and($comparison->commits[0]->sha)->toBe('6dcb09b5b57875f334f61aebed695e2e4193db5e')
        ->and($comparison->commits[0]->message)->toBe('Fix all the bugs')
        ->and($comparison->files[0]->filename)->toBe('file1.txt')
        ->and($comparison->raw()['permalink_url'])->toStartWith('https://github.com/octocat/Hello-World/compare/');
});

it('keeps a truncated diverged compare in order and says it was truncated', function (): void {
    // Unpaged, GitHub sends the NEWEST 250 commits, oldest first, and counts every one in
    // `total_commits` — so `totalCommits > count(commits)` is the truncation signal.
    Http::fake(['*/repos/o/r/compare/main...feature' => Http::response([
        'status' => 'diverged',
        'ahead_by' => 300,
        'behind_by' => 4,
        'total_commits' => 300,
        'commits' => array_map(compareCommitPayload(...), range(51, 300)),
        'files' => [],
    ])]);

    $comparison = github()->compare('o/r', 'main', 'feature');

    expect($comparison->status)->toBe(ComparisonStatus::Diverged)
        ->and($comparison->totalCommits)->toBe(300)
        ->and($comparison->commits)->toHaveCount(250)
        ->and($comparison->totalCommits > count($comparison->commits))->toBeTrue()
        ->and($comparison->commits[0]->sha)->toBe(sprintf('%040d', 51))
        ->and($comparison->commits[249]->sha)->toBe(sprintf('%040d', 300))
        ->and($comparison->commits[0]->provider)->toBe(ProviderName::Github)
        ->and($comparison->commits[0]->author->name)->toBe('Mona')
        ->and($comparison->raw())->not->toBeEmpty()
        ->and($comparison->raw()['total_commits'])->toBe(300);
});

it('maps each of github\'s compare statuses', function (string $wire, ComparisonStatus $status): void {
    Http::fake(['*/repos/o/r/compare/*' => Http::response(['status' => $wire, 'ahead_by' => 0, 'behind_by' => 0, 'total_commits' => 0])]);

    expect(github()->compare('o/r', 'a', 'b')->status)->toBe($status);
})->with([
    'ahead' => ['ahead', fn (): ComparisonStatus => ComparisonStatus::Ahead],
    'behind' => ['behind', fn (): ComparisonStatus => ComparisonStatus::Behind],
    'identical' => ['identical', fn (): ComparisonStatus => ComparisonStatus::Identical],
    'diverged' => ['diverged', fn (): ComparisonStatus => ComparisonStatus::Diverged],
]);

it('reads a status github has not documented as unknown, never as a guess', function (): void {
    // A forward-only guard must not read "ahead" off a value nobody told it about.
    Http::fake(['*/repos/o/r/compare/*' => Http::response(['status' => 'sideways', 'ahead_by' => 1, 'behind_by' => 0])]);

    $comparison = github()->compare('o/r', 'a', 'b');

    expect($comparison->status)->toBeNull()
        // An old answer without the field counts nothing it was not told.
        ->and($comparison->totalCommits)->toBeNull()
        ->and($comparison->commits)->toBe([]);
});

it('maps gitlab commits but leaves its status unknown', function (): void {
    // GitLab cannot tell diverged from ahead: a status here would be a guess.
    Http::fake(['*/repository/compare*' => Http::response([
        'commits' => [
            ['id' => 'a1', 'message' => 'first', 'author_name' => 'Jane', 'author_email' => 'j@e.x', 'authored_date' => '2026-01-01T00:00:00Z'],
            ['id' => 'b2', 'message' => 'second', 'author_name' => 'Jane', 'author_email' => 'j@e.x', 'authored_date' => '2026-01-02T00:00:00Z'],
        ],
        'diffs' => [['new_path' => 'a.php', 'new_file' => true]],
        'compare_timeout' => false,
    ])]);

    $comparison = gitlab()->compare('g/p', 'main', 'feature');

    expect($comparison->status)->toBeNull()
        ->and($comparison->totalCommits)->toBe(2)
        ->and($comparison->commits)->toHaveCount(2)
        ->and($comparison->commits[1]->sha)->toBe('b2')
        ->and($comparison->commits[1]->provider)->toBe(ProviderName::Gitlab)
        ->and($comparison->raw()['compare_timeout'])->toBeFalse();
});

it('still builds a comparison from the original positional arguments', function (): void {
    // The new parameters were appended AFTER `raw`, so 1.1 callers keep compiling.
    $comparison = new Comparison('main', 'feature', 1, 0, [], ['k' => 'v']);

    expect($comparison->raw())->toBe(['k' => 'v'])
        ->and($comparison->status)->toBeNull()
        ->and($comparison->totalCommits)->toBeNull()
        ->and($comparison->commits)->toBe([]);
});

it('serializes the new fields, the status as its wire value', function (): void {
    $comparison = new Comparison('main', 'feature', 1, 0, [], status: ComparisonStatus::Ahead, totalCommits: 1);

    expect($comparison->toArray())
        ->toMatchArray(['status' => 'ahead', 'totalCommits' => 1, 'commits' => []])
        ->not->toHaveKey('raw');
});

it('answers an identical, empty comparison from the fake by default', function (): void {
    $comparison = Git::fake()->github()->compare('o/r', 'main', 'main');

    expect($comparison->status)->toBe(ComparisonStatus::Identical)
        ->and($comparison->totalCommits)->toBe(0)
        ->and($comparison->commits)->toBe([]);
});

it('exposes the comparison statuses through the enum helpers', function (): void {
    expect(ComparisonStatus::values()->all())->toBe(['ahead', 'behind', 'identical', 'diverged']);
});
