<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Git\Dto\WebhookEvent;

final class PullRequestEventReceived
{
    use Dispatchable;

    public function __construct(
        public readonly WebhookEvent $event,
    ) {}
}
