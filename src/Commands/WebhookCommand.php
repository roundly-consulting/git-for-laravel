<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Registry;

final class WebhookCommand extends Command
{
    protected $signature = 'git:webhook {provider} {repo} {--url=} {--events=push} {--secret=} {--list} {--delete=}';

    protected $description = 'Register, list, or delete a repository webhook for a provider';

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

        $repoArgument = $this->argument('repo');
        $repo = is_string($repoArgument) ? $repoArgument : '';
        $webhooks = $provider->webhooks($repo);

        if ($this->option('list')) {
            $this->renderList($webhooks->all());

            return self::SUCCESS;
        }

        $deleteId = $this->option('delete');

        if (is_string($deleteId) && $deleteId !== '') {
            $webhooks->delete($deleteId);
            $this->info("Deleted webhook [{$deleteId}].");

            return self::SUCCESS;
        }

        $url = $this->option('url');
        $secret = $this->option('secret');

        $hook = $webhooks->register(
            url: is_string($url) && $url !== '' ? $url : null,
            events: $this->events(),
            secret: is_string($secret) && $secret !== '' ? $secret : null,
        );

        $this->info("Webhook [{$hook->id}] registered at {$hook->url}.");

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function events(): array
    {
        $option = $this->option('events');
        $events = is_string($option) ? $option : 'push';

        return array_values(array_filter(array_map('trim', explode(',', $events))));
    }

    /** @param list<Webhook> $hooks */
    private function renderList(array $hooks): void
    {
        $this->table(
            ['ID', 'URL', 'Events', 'Active'],
            array_map(fn (Webhook $hook): array => [
                $hook->id,
                $hook->url,
                implode(', ', $hook->events),
                $hook->active ? 'yes' : 'no',
            ], $hooks),
        );
    }
}
