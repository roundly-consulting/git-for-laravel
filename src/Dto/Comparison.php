<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\ComparisonStatus;

/**
 * Two refs compared.
 *
 * `commits` are oldest first. GitHub sends at most the NEWEST 250 of them on an unpaged
 * compare, while `totalCommits` counts every one — so `totalCommits > count($commits)` means
 * the list was cut. `files` stops at 300.
 *
 * `status` is GitHub's own verdict, or `null` where the forge gives none (GitLab) or sends a
 * value this package does not know.
 */
final readonly class Comparison extends Dto
{
    use HasRawPayload;

    /**
     * @param  list<ComparisonFile>  $files
     * @param  array<string, mixed>  $raw
     * @param  list<Commit>  $commits  oldest first
     */
    public function __construct(
        public string $base,
        public string $head,
        public int $aheadBy,
        public int $behindBy,
        public array $files,
        public array $raw = [],
        public ?ComparisonStatus $status = null,
        public ?int $totalCommits = null,
        public array $commits = [],
    ) {}
}
