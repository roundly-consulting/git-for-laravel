<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * How a completed GitHub Actions run, job or step ended.
 *
 * A conclusion GitHub adds later reads as {@see self::Unknown} — never as success. A run
 * that has not finished has no conclusion at all (`null`).
 */
enum WorkflowConclusion: string
{
    use Helpers;

    case Success = 'success';
    case Failure = 'failure';
    case Neutral = 'neutral';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';
    case TimedOut = 'timed_out';
    case ActionRequired = 'action_required';
    case Stale = 'stale';
    case StartupFailure = 'startup_failure';
    case Unknown = 'unknown';

    /** The conclusion a payload names: null for none, Unknown for one this package does not know. */
    public static function fromWire(mixed $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return (is_string($value) ? self::tryFrom($value) : null) ?? self::Unknown;
    }
}
