<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git;

use Illuminate\Support\ServiceProvider;

final class GitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/git.php', 'git');

        $this->app->singleton(Registry::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/git.php' => config_path('git.php'),
            ], 'git-config');
        }
    }
}
