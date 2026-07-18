<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Tests;

/**
 * The suite's base case with the webhook routes enabled and every provider's signing
 * secret set BEFORE the providers boot.
 *
 * Boot order is load-bearing: `git.webhooks.enabled` decides whether the provider
 * registers its routes at all, so setting it inside a test body would read back
 * correctly against a router that never got the route.
 *
 * Note the `array_merge(parent::configBeforeBoot(), …)`: dropping it would silently
 * discard whatever the parent wires, with no error and no red. It is empty today —
 * that is not a reason to omit it.
 */
class WebhookTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'git.webhooks.enabled' => true,
            'git.webhooks.middleware' => [],
            'git.providers.github.webhook_secret' => 'top-secret',
            'git.providers.gitlab.webhook_secret' => 'top-secret',
            'git.providers.bitbucket.webhook_secret' => 'top-secret',
        ]);
    }
}
