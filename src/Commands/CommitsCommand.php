<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\GitManager;
use RoundlyConsulting\Git\Query\CommitQuery;

final class CommitsCommand extends Command
{
    protected $signature = 'git:commits {provider} {path} {--branch=} {--since=}';

    protected $description = 'List commits for a repository path';

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

        $path = $this->argument('path');

        try {
            $query = $provider->repo(is_string($path) ? $path : '')->commits();
        } catch (OutOfScopeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->applyFilters($query);

        try {
            $commits = $query->collect();
        } catch (FeatureNotSupportedException $exception) {
            // Bitbucket cannot filter by date; say so instead of dumping a stack trace.
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['SHA', 'Author', 'Message'],
            $commits->map(fn (Commit $commit): array => [
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
