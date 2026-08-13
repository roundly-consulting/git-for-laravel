<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Credentials;

use RoundlyConsulting\Git\Auth\TokenManager;
use RoundlyConsulting\Git\Contracts\RefreshableCredentials;
use RoundlyConsulting\Git\Dto\Input\InstallationTokenScope;
use SensitiveParameter;

final readonly class GithubAppToken extends Credentials implements RefreshableCredentials
{
    public function __construct(
        public string $appId,
        public string $installationId,
        #[SensitiveParameter] public string $privateKey,
        public ?string $apiBaseUrl = null,
        public ?InstallationTokenScope $scope = null,
    ) {
        parent::__construct(null);
    }

    public static function for(
        string $appId,
        string $installationId,
        #[SensitiveParameter] string $privateKey,
        ?string $apiBaseUrl = null,
        ?InstallationTokenScope $scope = null,
    ): self {
        return new self($appId, $installationId, $privateKey, $apiBaseUrl, $scope);
    }

    /**
     * The same credential, narrowed to a scope.
     *
     * Returns a NEW instance — the DTO is readonly, and a caller that scopes a
     * credential must not silently narrow one somebody else is holding.
     */
    public function forScope(InstallationTokenScope $scope): self
    {
        return new self($this->appId, $this->installationId, $this->privateKey, $this->apiBaseUrl, $scope);
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
            // The digest, not the repository list: this is what the token cache is
            // keyed on, so it is the value you want when a mint hit or missed
            // unexpectedly. The list itself is on the scope if you need it.
            'scope' => $this->scope?->digest(),
        ];
    }
}
