<?php

declare(strict_types=1);

it('does not register the webhook route when disabled', function () {
    expect(app('router')->getRoutes()->getByName('git.webhooks'))->toBeNull();
});
