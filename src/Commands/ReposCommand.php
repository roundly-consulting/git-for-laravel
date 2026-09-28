<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\GitManager;

final class ReposCommand extends Command
{
    protected $signature = 'git:repos {provider} {--json}';

    protected $description = 'List the authenticated user\'s repositories for a provider';

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

        $repositories = $provider->repositories();

        if ($this->option('json')) {
            $this->line((string) json_encode($repositories->collect()->map->toArray()->all()));

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Path', 'Default branch'],
            $repositories->collect()->map(fn (Repository $repo): array => [
                $repo->name,
                $repo->path,
                $repo->defaultBranch,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
