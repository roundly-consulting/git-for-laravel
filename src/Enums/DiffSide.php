<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * Which side of a diff an inline review comment is anchored to.
 *
 * `Right` — the version being proposed — is what a review of a change almost always
 * means, and it is the default everywhere in this package. `Left` anchors to the file
 * as it is today, which is what a comment about a DELETED line needs: the line does not
 * exist on the right, so anchoring it there is the `422` this enum exists to make
 * deliberate rather than accidental.
 */
enum DiffSide: string
{
    use Helpers;

    /** The base — the file before the change. Where a deleted line lives. */
    case Left = 'left';

    /** The head — the file as the pull request proposes it. */
    case Right = 'right';

    /** The forge's own spelling. */
    public function wire(): string
    {
        return match ($this) {
            self::Left => 'LEFT',
            self::Right => 'RIGHT',
        };
    }
}
