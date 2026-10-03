<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Git\Support\Settings;
use RoundlyConsulting\Git\Webhooks\WebhookController;

/** @var array<int, string> $middleware */
$middleware = config('git.webhooks.middleware', ['api']);

// Unset or blank means the default; surrounding slashes are trimmed, and a non-string or
// slash-only path throws rather than mounting the receiver at the site root.
$path = Settings::routePath('git.webhooks.path', config('git.webhooks.path'), 'git/webhooks');

Route::middleware($middleware)
    ->post($path.'/{provider}', WebhookController::class)
    ->name('git.webhooks');
