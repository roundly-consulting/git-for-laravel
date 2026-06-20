<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Git\Auth\TokenManager;
use RoundlyConsulting\Git\Commands\CommitsCommand;
use RoundlyConsulting\Git\Commands\RateLimitCommand;
use RoundlyConsulting\Git\Commands\ReposCommand;
use RoundlyConsulting\Git\Commands\WebhookCommand;
use RoundlyConsulting\Git\Mapping\BitbucketMapper;
use RoundlyConsulting\Git\Mapping\GithubMapper;
use RoundlyConsulting\Git\Mapping\GitlabMapper;
use RoundlyConsulting\Git\Webhooks\Mapping\BitbucketWebhookMapper;
use RoundlyConsulting\Git\Webhooks\Mapping\GithubWebhookMapper;
use RoundlyConsulting\Git\Webhooks\Mapping\GitlabWebhookMapper;

final class GitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/git.php', 'git');

        $this->app->singleton(Registry::class);

        $this->app->singleton(GithubMapper::class);
        $this->app->singleton(GitlabMapper::class);
        $this->app->singleton(BitbucketMapper::class);

        $this->app->singleton(GithubWebhookMapper::class);
        $this->app->singleton(GitlabWebhookMapper::class);
        $this->app->singleton(BitbucketWebhookMapper::class);

        $this->app->singleton(TokenManager::class);
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
                WebhookCommand::class,
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
