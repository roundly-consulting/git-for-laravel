<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Webhooks;

use InvalidArgumentException;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Handles\PathGuard;
use RoundlyConsulting\Git\Interfaces\Provider;
use RoundlyConsulting\Git\Support\Settings;
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
     *
     * @throws InvalidArgumentException when the URL is the package route and the hook would
     *                                  carry no secret, or another secret than the route checks
     */
    public function register(
        ?string $url = null,
        array $events = ['push'],
        #[SensitiveParameter] ?string $secret = null,
    ): Webhook {
        $derived = $url === null;
        $url ??= $this->derivedUrl();
        $configured = $this->defaultSecret();
        $secret ??= $configured;

        if ($derived) {
            $this->guardVerifiable($secret, $configured);
        }

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

    /**
     * The package's own route verifies every delivery against the CONFIGURED secret and
     * answers 403 when there is none — so a hook pointed at it with no secret, or with a
     * different one, could never deliver. Refused before anything is sent.
     */
    private function guardVerifiable(#[SensitiveParameter] ?string $secret, #[SensitiveParameter] ?string $configured): void
    {
        $key = "git.providers.{$this->provider->providerName()->key()}.webhook_secret";

        if ($configured === null) {
            throw new InvalidArgumentException(
                "Cannot register the package's webhook route without a secret it can verify: set [{$key}], or pass an explicit \$url for a route you own."
            );
        }

        if ($secret !== $configured) {
            throw new InvalidArgumentException(
                "The package's webhook route verifies deliveries against [{$key}]: pass that secret (or none), or an explicit \$url for a route you own."
            );
        }
    }

    private function defaultSecret(): ?string
    {
        return Settings::filled(config("git.providers.{$this->provider->providerName()->key()}.webhook_secret"));
    }
}
