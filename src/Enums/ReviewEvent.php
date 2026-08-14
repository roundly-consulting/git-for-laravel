<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Enums;

use RoundlyConsulting\Enums\Helpers;

/**
 * What a review SAYS about the pull request it is left on.
 *
 * A closed set, because the three verdicts are not interchangeable to a forge: an
 * account may leave a `Comment` on any pull request, while `Approve` and
 * `RequestChanges` are refused (`422`) on one the same account opened. A caller that
 * authors pull requests can therefore only ever post `Comment` on its own work — which
 * is a fact about the forge, not a limitation of this package, and the reason the
 * verdict a reader acts on belongs in the review BODY rather than in this field.
 */
enum ReviewEvent: string
{
    use Helpers;

    /** A review with findings and no verdict of its own. Always permitted. */
    case Comment = 'comment';

    /** Approve. Refused on a pull request the reviewing account opened. */
    case Approve = 'approve';

    /** Ask for changes. Refused on a pull request the reviewing account opened. */
    case RequestChanges = 'request_changes';

    /**
     * The forge's own spelling of this verdict.
     *
     * Written out rather than derived from `$this->value` so the wire format cannot
     * change by renaming a case.
     */
    public function wire(): string
    {
        return match ($this) {
            self::Comment => 'COMMENT',
            self::Approve => 'APPROVE',
            self::RequestChanges => 'REQUEST_CHANGES',
        };
    }
}
