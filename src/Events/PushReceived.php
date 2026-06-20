<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\WebhookEvent;

final class PushReceived
{
    use Dispatchable;

    public function __construct(
        public readonly WebhookEvent $event,
    ) {}

    /** @return list<Commit> */
    public function commits(): array
    {
        return $this->event->commits();
    }

    public function ref(): ?string
    {
        return $this->event->ref();
    }

    public function repository(): ?Repository
    {
        return $this->event->repository();
    }

    public function pusher(): ?Author
    {
        return $this->event->pusher();
    }
}
