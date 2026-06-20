<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Credentials;

use Carbon\CarbonInterface;
use RoundlyConsulting\Git\Auth\TokenManager;
use RoundlyConsulting\Git\Contracts\RefreshableCredentials;
use SensitiveParameter;

final readonly class OauthToken extends Credentials implements RefreshableCredentials
{
    public function __construct(
        #[SensitiveParameter] public string $accessTokenValue,
        #[SensitiveParameter] public string $refreshToken,
        public string $clientId,
        #[SensitiveParameter] public string $clientSecret,
        public string $tokenUrl,
        public ?CarbonInterface $expiresAt = null,
    ) {
        parent::__construct(null);
    }

    public static function for(
        #[SensitiveParameter] string $accessToken,
        #[SensitiveParameter] string $refreshToken,
        string $clientId,
        #[SensitiveParameter] string $clientSecret,
        string $tokenUrl,
        ?CarbonInterface $expiresAt = null,
    ): self {
        return new self($accessToken, $refreshToken, $clientId, $clientSecret, $tokenUrl, $expiresAt);
    }

    public function accessToken(): string
    {
        return resolve(TokenManager::class)->oauthToken($this);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'clientId' => $this->clientId,
            'accessToken' => '••••',
            'refreshToken' => '••••',
            'clientSecret' => '••••',
            'expiresAt' => $this->expiresAt?->toIso8601String(),
        ];
    }
}
