<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Credentials;

use RoundlyConsulting\Git\Auth\GithubAppJwt;
use RoundlyConsulting\Git\Contracts\RefreshableCredentials;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use SensitiveParameter;

/**
 * The app itself, not an installation.
 *
 * GitHub's `/app/**` endpoints (looking an installation up, listing them) are
 * authenticated with the app's own RS256 JWT rather than an installation token —
 * which is what lets a service VERIFY an installation id it was handed before
 * trusting it. {@see GithubAppToken} is the other half: the same key, plus an
 * installation, exchanged for a short-lived access token.
 *
 * Deliberately uncached: the JWT is a local RSA signature with a nine-minute life, so
 * caching it would put a bearer token in a store to save a signature.
 */
final readonly class GithubApp extends Credentials implements RefreshableCredentials
{
    /**
     * `apiBaseUrl` is carried for parity with {@see GithubAppToken} and for a caller that
     * wants to record which host a credential was built for; the provider itself takes its
     * base URL from `git.providers.github.url`, so nothing here reads it.
     *
     * @throws InvalidCredentialsException when the app id is not numeric
     */
    public function __construct(
        public string $appId,
        #[SensitiveParameter] public string $privateKey,
        public ?string $apiBaseUrl = null,
    ) {
        parent::__construct(null);

        if ($appId === '' || ! ctype_digit($appId)) {
            throw InvalidCredentialsException::invalidAppIdentifier('app id', $appId);
        }
    }

    public static function for(
        string $appId,
        #[SensitiveParameter] string $privateKey,
        ?string $apiBaseUrl = null,
    ): self {
        return new self($appId, $privateKey, $apiBaseUrl);
    }

    public function accessToken(): string
    {
        return (new GithubAppJwt($this->appId, $this->privateKey))->issue();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'appId' => $this->appId,
            'privateKey' => '••••',
            'apiBaseUrl' => $this->apiBaseUrl,
        ];
    }
}
