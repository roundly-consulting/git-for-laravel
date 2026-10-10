<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * What happened to a ref in a repository's activity feed (GitHub).
 *
 * A type GitHub adds later reads as {@see self::Unknown} rather than throwing; the
 * original value stays reachable through the DTO's `raw()`.
 */
enum ActivityType: string
{
    use Helpers;

    case Push = 'push';
    case ForcePush = 'force_push';
    case BranchCreation = 'branch_creation';
    case BranchDeletion = 'branch_deletion';
    case PullRequestMerge = 'pr_merge';
    case MergeQueueMerge = 'merge_queue_merge';
    case Unknown = 'unknown';
}
