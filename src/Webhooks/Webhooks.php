<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Webhooks;

use InvalidArgumentException;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Handles\PathGuard;
use RoundlyConsulting\Git\Interfaces\Provider;
use RoundlyConsulting\PackageToolkit\Support\Config;
use SensitiveParameter;

/**
 * Ergonomic, idempotent webhook lifecycle for a single repository — ties this
 * app's inbound route to the provider's outbound create-webhook write op.
 */
final class Webhooks
{
    public function __construct(
        private readonly Provider $provider,
        private readonly string $path,
    ) {}

    /**
     * Register this app's inbound endpoint. The URL is derived from the
     * published `git.webhooks` route when omitted; the secret defaults to the
     * provider's configured webhook secret so the hook is immediately verifiable.
     *
     * @param  list<string>  $events
     */
    public function register(
        ?string $url = null,
        array $events = ['push'],
        #[SensitiveParameter] ?string $secret = null,
    ): Webhook {
        $url ??= $this->derivedUrl();
        $secret ??= $this->defaultSecret();

        foreach ($this->all() as $existing) {
            if ($existing->url === $url) {
                return $existing;
            }
        }

        return $this->provider->createWebhook($this->path, new NewWebhook(
            url: $url,
            events: $events,
            secret: $secret,
        ));
    }

    /** @return list<Webhook> */
    public function all(): array
    {
        return $this->provider->listWebhooks($this->path);
    }

    /**
     * Delete one hook by the id the forge issued — numeric on GitHub and GitLab, a braced
     * `{uuid}` on Bitbucket.
     *
     * @throws OutOfScopeException when the id has any other shape: it lands in a DELETE URL
     */
    public function delete(string $id): void
    {
        $this->provider->deleteWebhook($this->path, PathGuard::webhookId($this->provider->providerName(), $id));
    }

    public function deleteByUrl(string $url): bool
    {
        $deleted = false;

        foreach ($this->all() as $hook) {
            if ($hook->url === $url) {
                $this->provider->deleteWebhook($this->path, $hook->id);
                $deleted = true;
            }
        }

        return $deleted;
    }

    public function registered(string $url): bool
    {
        foreach ($this->all() as $hook) {
            if ($hook->url === $url) {
                return true;
            }
        }

        return false;
    }

    private function derivedUrl(): string
    {
        if (! Config::boolean('git.webhooks.enabled')) {
            throw new InvalidArgumentException(
                'Cannot derive the webhook URL: enable [git.webhooks.enabled] or pass an explicit $url.'
            );
        }

        return route('git.webhooks', ['provider' => $this->provider->providerName()->key()]);
    }

    private function defaultSecret(): ?string
    {
        $secret = config("git.providers.{$this->provider->providerName()->key()}.webhook_secret");

        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
