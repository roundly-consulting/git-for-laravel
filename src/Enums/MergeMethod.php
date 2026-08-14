<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * How a pull request's commits land on the target branch.
 *
 * An enum rather than the provider's own string because the three values are a closed
 * set that a repository can *disallow*: asking for one it refuses is a `405` that reads
 * like a permissions problem. A typo ('sqaush') is the same `405`, and this is the
 * difference between catching it at the call site and catching it in production.
 */
enum MergeMethod: string
{
    use Helpers;

    /** A merge commit with both parents — the default, and the only one that keeps history intact. */
    case Merge = 'merge';

    /** One commit on the target branch, authored by whoever merged. */
    case Squash = 'squash';

    /** Every commit replayed onto the target branch, losing the pull request's own merge point. */
    case Rebase = 'rebase';
}
