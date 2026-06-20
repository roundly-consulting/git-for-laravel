<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\WebhookEvent;

final class PullRequestEventReceived
{
    use Dispatchable;

    public function __construct(
        public readonly WebhookEvent $event,
    ) {}

    public function pullRequest(): ?PullRequest
    {
        return $this->event->pullRequest();
    }

    public function action(): string
    {
        return $this->event->type;
    }

    public function repository(): ?Repository
    {
        return $this->event->repository();
    }
}
