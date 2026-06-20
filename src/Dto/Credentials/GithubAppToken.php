<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Credentials;

use RoundlyConsulting\Git\Auth\TokenManager;
use RoundlyConsulting\Git\Contracts\RefreshableCredentials;
use SensitiveParameter;

final readonly class GithubAppToken extends Credentials implements RefreshableCredentials
{
    public function __construct(
        public string $appId,
        public string $installationId,
        #[SensitiveParameter] public string $privateKey,
        public ?string $apiBaseUrl = null,
    ) {
        parent::__construct(null);
    }

    public static function for(
        string $appId,
        string $installationId,
        #[SensitiveParameter] string $privateKey,
        ?string $apiBaseUrl = null,
    ): self {
        return new self($appId, $installationId, $privateKey, $apiBaseUrl);
    }

    public function accessToken(): string
    {
        return resolve(TokenManager::class)->installationToken($this);
    }

    public function baseUrl(): string
    {
        return $this->apiBaseUrl ?? 'https://api.github.com';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'appId' => $this->appId,
            'installationId' => $this->installationId,
            'privateKey' => '••••',
            'apiBaseUrl' => $this->apiBaseUrl,
        ];
    }
}
