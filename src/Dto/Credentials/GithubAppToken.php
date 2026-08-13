<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Credentials;

use RoundlyConsulting\Git\Auth\TokenManager;
use RoundlyConsulting\Git\Contracts\RefreshableCredentials;
use RoundlyConsulting\Git\Dto\Input\InstallationTokenScope;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use SensitiveParameter;

final readonly class GithubAppToken extends Credentials implements RefreshableCredentials
{
    /**
     * @throws InvalidCredentialsException when the app or installation id is not numeric
     */
    public function __construct(
        public string $appId,
        public string $installationId,
        #[SensitiveParameter] public string $privateKey,
        public ?string $apiBaseUrl = null,
        public ?InstallationTokenScope $scope = null,
    ) {
        parent::__construct(null);

        // Both are GitHub numeric ids, and both end up inside a cache key built by
        // concatenation. An `installationId` of "999:<64 hex chars>" produces a key
        // byte-identical to the SCOPED key for installation 999 — and the cache is read
        // before any HTTP call, so a consumer that forwards an unvalidated id (say,
        // straight off a redirect's query string) could be served another tenant's
        // repository-scoped token. Reject the shape here, once, rather than trusting every
        // call site to have checked.
        foreach (['app id' => $appId, 'installation id' => $installationId] as $label => $value) {
            if ($value === '' || ! ctype_digit($value)) {
                throw InvalidCredentialsException::invalidAppIdentifier($label, $value);
            }
        }
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
