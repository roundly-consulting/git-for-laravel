<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * How the head of a comparison relates to its base, as GitHub reports it.
 *
 * Only GitHub answers this. GitLab's compare endpoint cannot tell `diverged` from `ahead`,
 * so a GitLab comparison carries no status at all rather than a guess a forward-only check
 * would trust.
 */
enum ComparisonStatus: string
{
    use Helpers;

    /** The head has commits the base lacks, and nothing else. */
    case Ahead = 'ahead';

    /** The base has commits the head lacks, and nothing else. */
    case Behind = 'behind';

    case Identical = 'identical';

    /** Each side has commits the other lacks. */
    case Diverged = 'diverged';
}
