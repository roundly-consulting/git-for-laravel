<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Tests;

class WebhookTestCase extends TestCase
{
    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('git.webhooks.enabled', true);
        $app['config']->set('git.webhooks.middleware', []);
        $app['config']->set('git.providers.github.webhook_secret', 'top-secret');
        $app['config']->set('git.providers.gitlab.webhook_secret', 'top-secret');
        $app['config']->set('git.providers.bitbucket.webhook_secret', 'top-secret');
    }
}
