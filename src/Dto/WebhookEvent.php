<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Webhooks\Mapping\BitbucketWebhookMapper;
use RoundlyConsulting\Git\Webhooks\Mapping\GithubWebhookMapper;
use RoundlyConsulting\Git\Webhooks\Mapping\GitlabWebhookMapper;
use RoundlyConsulting\Git\Webhooks\Mapping\WebhookPayloadMapper;

final readonly class WebhookEvent extends Dto
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public ProviderName $provider,
        public string $type,
        public array $payload,
    ) {}

    /**
     * A branch or tag push. GitHub's `push` and Bitbucket's `repo:push` cover both; GitLab
     * sends a tag push as its own `Tag Push Hook`.
     */
    public function isPush(): bool
    {
        return in_array($this->type, ['push', 'repo:push', 'Push Hook', 'Tag Push Hook'], true);
    }

    public function isPullRequest(): bool
    {
        return in_array($this->type, [
            'pull_request',
            'pullrequest:created',
            'pullrequest:updated',
            'pullrequest:fulfilled',
            'pullrequest:rejected',
            'Merge Request Hook',
        ], true);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    /**
     * Alias of {@see payload()} for parity with the resource DTOs' raw escape hatch.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->payload;
    }

    /** @return list<Commit> */
    public function commits(): array
    {
        return $this->mapper()->commits($this->payload);
    }

    public function pullRequest(): ?PullRequest
    {
        return $this->mapper()->pullRequest($this->payload);
    }

    public function repository(): ?Repository
    {
        return $this->mapper()->repository($this->payload);
    }

    public function ref(): ?string
    {
        return $this->mapper()->ref($this->payload);
    }

    public function pusher(): ?Author
    {
        return $this->mapper()->pusher($this->payload);
    }

    private function mapper(): WebhookPayloadMapper
    {
        return resolve(match ($this->provider) {
            ProviderName::Github => GithubWebhookMapper::class,
            ProviderName::Gitlab => GitlabWebhookMapper::class,
            ProviderName::Bitbucket => BitbucketWebhookMapper::class,
        });
    }
}
