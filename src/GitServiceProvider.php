<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git;

use RoundlyConsulting\Git\Auth\TokenManager;
use RoundlyConsulting\Git\Commands\CommitsCommand;
use RoundlyConsulting\Git\Commands\RateLimitCommand;
use RoundlyConsulting\Git\Commands\ReposCommand;
use RoundlyConsulting\Git\Commands\WebhookCommand;
use RoundlyConsulting\Git\Mapping\BitbucketMapper;
use RoundlyConsulting\Git\Mapping\GithubMapper;
use RoundlyConsulting\Git\Mapping\GitlabMapper;
use RoundlyConsulting\Git\Support\Settings;
use RoundlyConsulting\Git\Webhooks\Mapping\BitbucketWebhookMapper;
use RoundlyConsulting\Git\Webhooks\Mapping\GithubWebhookMapper;
use RoundlyConsulting\Git\Webhooks\Mapping\GitlabWebhookMapper;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class GitServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('git')
            ->hasConfigFile()
            ->hasRoutes('git-webhooks.php', 'git.webhooks.enabled')
            ->hasCommands([
                ReposCommand::class,
                RateLimitCommand::class,
                CommitsCommand::class,
                WebhookCommand::class,
            ])
            ->contributesToAbout(static fn (): array => [
                'Providers' => self::credentialedProviders(),
                'Rate limiting' => self::throttledProviders(),
                'Webhooks' => Config::boolean('git.webhooks.enabled') ? (string) config('git.webhooks.path', 'git/webhooks') : 'OFF',
                'Conditional caching' => Config::boolean('git.cache.enabled') ? 'ON' : 'OFF',
            ]);
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(GitManager::class);

        $this->app->singleton(GithubMapper::class);
        $this->app->singleton(GitlabMapper::class);
        $this->app->singleton(BitbucketMapper::class);

        $this->app->singleton(GithubWebhookMapper::class);
        $this->app->singleton(GitlabWebhookMapper::class);
        $this->app->singleton(BitbucketWebhookMapper::class);

        $this->app->singleton(TokenManager::class);
    }

    /**
     * The providers a host has actually given a default credential, so `about`
     * shows which ones work without an explicit token.
     */
    private static function credentialedProviders(): string
    {
        $configured = array_keys(array_filter(
            self::providers(),
            static fn (array $provider): bool => is_string($provider['token'] ?? null) && $provider['token'] !== '',
        ));

        return $configured === [] ? 'NONE' : implode(', ', $configured);
    }

    /**
     * The providers still paced by the client-side limiter, so `about` shows at
     * a glance which ones a host has switched off.
     */
    private static function throttledProviders(): string
    {
        $enabled = array_keys(array_filter(
            self::providers(),
            static fn (array $provider, string $name): bool => Settings::boolean("git.providers.{$name}.rateLimits.enabled", $provider['rateLimits']['enabled'] ?? null, true),
            ARRAY_FILTER_USE_BOTH,
        ));

        return $enabled === [] ? 'OFF' : implode(', ', $enabled);
    }

    /** @return array<string, array<string, mixed>> */
    private static function providers(): array
    {
        /** @var array<string, array<string, mixed>> $providers */
        $providers = config('git.providers', []);

        return $providers;
    }
}
