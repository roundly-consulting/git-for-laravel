<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\Git\Webhooks\WebhookController;

/** @var array<int, string> $middleware */
$middleware = config('git.webhooks.middleware', ['api']);

/** @var string $path */
$path = config('git.webhooks.path', 'git/webhooks');

Route::middleware($middleware)
    ->post($path.'/{provider}', WebhookController::class)
    ->name('git.webhooks');
