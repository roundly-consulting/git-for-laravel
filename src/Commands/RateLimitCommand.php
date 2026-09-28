<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\GitManager;

final class RateLimitCommand extends Command
{
    protected $signature = 'git:rate-limit {provider}';

    protected $description = 'Show the current rate-limit status for a provider';

    public function handle(GitManager $git): int
    {
        $argument = $this->argument('provider');
        $name = is_string($argument) ? ProviderName::tryFrom($argument) : null;

        if ($name === null) {
            $this->error('Unknown provider.');

            return self::FAILURE;
        }

        $provider = $git->provider($name);

        if (! $provider->isAuthenticated()) {
            $this->error("No credentials for [{$name->label()}]. Set the provider token in config (e.g. GITHUB_TOKEN).");

            return self::FAILURE;
        }

        // Any authenticated read carries the rate-limit headers. An installation token
        // cannot read `/user` (403), so a configured GitHub App asks its installation.
        if ($git->credentials($name) instanceof GithubAppToken) {
            $provider->installationRepositories(1);
        } else {
            $provider->user();
        }

        $status = $provider->rateLimit();

        if ($status === null) {
            $this->warn('No rate-limit information available from the last response.');

            return self::SUCCESS;
        }

        $this->table(['Limit', 'Remaining', 'Used', 'Resets at'], [[
            $status->limit,
            $status->remaining,
            $status->used,
            $status->resetAt?->toDateTimeString() ?? '—',
        ]]);

        return self::SUCCESS;
    }
}
