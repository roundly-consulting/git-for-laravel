<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Contracts;

/**
 * A driver that can say how its `listWebhooks()` would report a set of events — so
 * `Webhooks::register()` compares the events asked for with an existing hook's in one form.
 *
 * @internal implemented by the built-in drivers and the fake.
 */
interface ListsWebhookEvents
{
    /**
     * @param  list<string>  $events  events as `createWebhook()` takes them
     * @return list<string> the same events as `listWebhooks()` reports them
     */
    public function listedWebhookEvents(array $events): array;
}
