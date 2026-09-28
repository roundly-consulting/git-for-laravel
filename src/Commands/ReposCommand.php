<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\GitManager;

final class ReposCommand extends Command
{
    protected $signature = 'git:repos {provider} {--json}';

    protected $description = 'List the repositories the configured credential can reach (a GitHub App: its installation\'s)';

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

        // An installation token has no user behind it — `/user/repos` answers 403 — so a
        // configured GitHub App lists what its installation can reach instead.
        $repositories = $git->credentials($name) instanceof GithubAppToken
            ? $provider->installationRepositories()
            : $provider->repositories();

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
