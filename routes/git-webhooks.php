<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Git\Support\Settings;
use RoundlyConsulting\Git\Webhooks\WebhookController;

/** @var array<int, string> $middleware */
$middleware = config('git.webhooks.middleware', ['api']);

// Unset means the default; a blank or non-string path throws rather than mounting the
// receiver at the site root.
$path = Settings::string('git.webhooks.path', config('git.webhooks.path'), 'git/webhooks');

Route::middleware($middleware)
    ->post($path.'/{provider}', WebhookController::class)
    ->name('git.webhooks');
