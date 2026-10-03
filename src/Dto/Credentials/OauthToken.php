<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Credentials;

use Carbon\CarbonInterface;
use RoundlyConsulting\Git\Auth\TokenManager;
use RoundlyConsulting\Git\Contracts\RefreshableCredentials;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Support\Settings;
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

    /**
     * Build an OAuth credential from the provider's configured client, supplying only the
     * per-user half of it.
     *
     * The split is what config is for. `client_id`, `client_secret`, and `token_url` are
     * static properties of the registered OAuth app — one per provider, the same for every
     * user — while the access and refresh tokens belong to whoever authorised. This is the
     * `app.*` block's precedent: `Git::github()` already mints installation tokens from
     * config alone, because a GitHub App has no per-user half. OAuth cannot go that far (no
     * refresh token can live in config), so it stops exactly here.
     *
     * Until this existed, `git.providers.*.oauth.*` was shipped, documented in the README's
     * config table, and read by **nothing** — setting `GITHUB_OAUTH_CLIENT_ID` did nothing
     * at all.
     *
     * @throws InvalidCredentialsException when the provider ships no OAuth client
     */
    public static function forProvider(
        ProviderName $provider,
        #[SensitiveParameter] string $accessToken,
        #[SensitiveParameter] string $refreshToken,
        ?CarbonInterface $expiresAt = null,
    ): self {
        $key = $provider->key();

        $clientId = Settings::filled(config("git.providers.{$key}.oauth.client_id"))
            ?? throw InvalidCredentialsException::missingOauthConfig($key, 'client_id');
        $clientSecret = Settings::filled(config("git.providers.{$key}.oauth.client_secret"))
            ?? throw InvalidCredentialsException::missingOauthConfig($key, 'client_secret');
        $tokenUrl = Settings::filled(config("git.providers.{$key}.oauth.token_url"))
            ?? throw InvalidCredentialsException::missingOauthConfig($key, 'token_url');

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
