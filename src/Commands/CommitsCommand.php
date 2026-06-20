<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Providers\Bitbucket;
use RoundlyConsulting\Git\Providers\Github;
use RoundlyConsulting\Git\Providers\Gitlab;
use RoundlyConsulting\Git\Query\CommitQuery;
use RoundlyConsulting\Git\Registry;

final class CommitsCommand extends Command
{
    protected $signature = 'git:commits {provider} {path} {--branch=} {--since=}';

    protected $description = 'List commits for a repository path';

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

        if (! $provider instanceof Github && ! $provider instanceof Gitlab && ! $provider instanceof Bitbucket) {
            $this->error('Provider does not support listing commits.');

            return self::FAILURE;
        }

        $path = $this->argument('path');
        $query = $provider->commits(is_string($path) ? $path : '');

        $this->applyFilters($query);

        $this->table(
            ['SHA', 'Author', 'Message'],
            $query->collect()->map(fn (Commit $commit): array => [
                substr($commit->sha, 0, 8),
                $commit->author->name,
                str($commit->message)->limit(50)->toString(),
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function applyFilters(CommitQuery $query): void
    {
        if (is_string($branch = $this->option('branch')) && $branch !== '') {
            $query->branch($branch);
        }

        if (is_string($since = $this->option('since')) && $since !== '') {
            $query->since($since);
        }
    }
}
