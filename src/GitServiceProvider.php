<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Git\Commands\CommitsCommand;
use RoundlyConsulting\Git\Commands\RateLimitCommand;
use RoundlyConsulting\Git\Commands\ReposCommand;

final class GitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/git.php', 'git');

        $this->app->singleton(Registry::class);
    }

    public function boot(): void
    {
        if (config('git.webhooks.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/webhooks.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReposCommand::class,
                RateLimitCommand::class,
                CommitsCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/git.php' => config_path('git.php'),
            ], 'git-config');

            $this->publishes([
                __DIR__.'/../routes/webhooks.php' => base_path('routes/git-webhooks.php'),
            ], 'git-routes');
        }
    }
}
