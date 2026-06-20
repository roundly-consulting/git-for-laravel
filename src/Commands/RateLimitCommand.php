<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Registry;

final class RateLimitCommand extends Command
{
    protected $signature = 'git:rate-limit {provider}';

    protected $description = 'Show the current rate-limit status for a provider';

    public function handle(Registry $registry): int
    {
        $argument = $this->argument('provider');
        $name = is_string($argument) ? ProviderName::tryFrom($argument) : null;

        if ($name === null) {
            $this->error('Unknown provider.');

            return self::FAILURE;
        }

        $provider = $registry->provider($name);

        if (! $provider->isAuthenticated()) {
            $this->error("No credentials for [{$name->label()}]. Set the provider token in config (e.g. GITHUB_TOKEN).");

            return self::FAILURE;
        }

        $provider->user();

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
