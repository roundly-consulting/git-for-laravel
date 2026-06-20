<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use InvalidArgumentException;
use SensitiveParameter;

final readonly class NewWebhook
{
    /** @param list<string> $events */
    public function __construct(
        public string $url,
        public array $events = ['push'],
        #[SensitiveParameter]
        public ?string $secret = null,
        public bool $active = true,
    ) {
        if (trim($url) === '') {
            throw new InvalidArgumentException('Webhook url must not be empty.');
        }

        if ($events === []) {
            throw new InvalidArgumentException('Webhook must subscribe to at least one event.');
        }
    }
}
