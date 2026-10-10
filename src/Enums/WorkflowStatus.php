<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * Where a GitHub Actions run, job or step is in its life.
 *
 * Read with `tryFrom() ?? Unknown`: a status GitHub adds later — or a `null` one — never
 * throws, and never reads as completed.
 */
enum WorkflowStatus: string
{
    use Helpers;

    case Requested = 'requested';
    case Queued = 'queued';
    case Pending = 'pending';
    case Waiting = 'waiting';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Unknown = 'unknown';

    /** The status a payload names, Unknown for anything this package does not know. */
    public static function fromWire(mixed $value): self
    {
        return (is_string($value) ? self::tryFrom($value) : null) ?? self::Unknown;
    }
}
