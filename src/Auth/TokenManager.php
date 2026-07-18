<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Auth;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Crypto\Hash\Digest;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Credentials\OauthToken;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use SensitiveParameter;

/**
 * Mints and caches expiring access tokens for GitHub App installations and
 * OAuth credentials, refreshing them transparently when they lapse.
 */
final class TokenManager
{
    /** Seconds shaved off the real expiry so a token is never used at the edge. */
    private const SAFETY_MARGIN = 60;

    public function installationToken(GithubAppToken $cred): string
    {
        $key = 'git:app:'.$cred->appId.':'.$cred->installationId;

        /** @var array{token: string, expires_at: int}|null $cached */
        $cached = $this->cache()->get($key);

        if (is_array($cached) && $cached['expires_at'] > time()) {
            return $cached['token'];
        }

        $jwt = (new GithubAppJwt($cred->appId, $cred->privateKey))->issue();

        $response = Http::asJson()
            ->acceptJson()
            ->withToken($jwt)
            ->post(rtrim($cred->baseUrl(), '/')."/app/installations/{$cred->installationId}/access_tokens");

        $response->throw();

        $token = $response->json('token');
        $expiresAt = $response->json('expires_at');

        if (! is_string($token) || $token === '') {
            throw InvalidCredentialsException::invalidKey('the installation token response was malformed.');
        }

        $expiresTimestamp = is_string($expiresAt)
            ? Carbon::parse($expiresAt)->getTimestamp()
            : time() + 3600;

        $this->store($key, $token, $expiresTimestamp);

        return $token;
    }

    public function oauthToken(OauthToken $cred): string
    {
        $key = $this->oauthCacheKey($cred->refreshToken);

        /** @var array{token: string, expires_at: int, refresh_token: string}|null $cached */
        $cached = $this->cache()->get($key);

        if (is_array($cached) && $cached['expires_at'] > time()) {
            return $cached['token'];
        }

        if ($cached === null
            && $cred->expiresAt !== null
            && $cred->expiresAt->getTimestamp() - self::SAFETY_MARGIN > time()) {
            return $cred->accessTokenValue;
        }

        return $this->refreshOauth($cred, $key);
    }

    private function refreshOauth(OauthToken $cred, string $key): string
    {
        $response = Http::asForm()
            ->acceptJson()
            ->post($cred->tokenUrl, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $cred->refreshToken,
                'client_id' => $cred->clientId,
                'client_secret' => $cred->clientSecret,
            ]);

        $response->throw();

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');
        $rotated = $response->json('refresh_token');

        if (! is_string($token) || $token === '') {
            throw InvalidCredentialsException::invalidKey('the OAuth refresh response was malformed.');
        }

        $expiresTimestamp = is_numeric($expiresIn) ? time() + (int) $expiresIn : time() + 3600;
        $refreshToken = is_string($rotated) && $rotated !== '' ? $rotated : $cred->refreshToken;

        $this->store($key, $token, $expiresTimestamp, $refreshToken);

        // A rotated refresh token re-keys the cache so the next lookup hits.
        if ($refreshToken !== $cred->refreshToken) {
            $this->store($this->oauthCacheKey($refreshToken), $token, $expiresTimestamp, $refreshToken);
        }

        return $token;
    }

    /**
     * The cache key for a refresh token, which is digested rather than embedded so a
     * live credential never lands in a cache key.
     *
     * `Digest` is crypto's deterministic-digest primitive — `hash('sha256', …)` verbatim,
     * so the keys are byte-identical to the ones the two inlined calls this replaces
     * produced. It exists as one method rather than two inlined calls because the two
     * had to agree: the rotation path re-keys the cache, and a drift between them would
     * strand the rotated token under a key the next lookup never reads.
     */
    private function oauthCacheKey(#[SensitiveParameter] string $refreshToken): string
    {
        return 'git:oauth:'.(new Digest)->hex($refreshToken);
    }

    private function store(string $key, string $token, int $expiresAt, ?string $refreshToken = null): void
    {
        $payload = ['token' => $token, 'expires_at' => $expiresAt - self::SAFETY_MARGIN];

        if ($refreshToken !== null) {
            $payload['refresh_token'] = $refreshToken;
        }

        $ttl = max(1, $expiresAt - self::SAFETY_MARGIN - time());

        $this->cache()->put($key, $payload, $ttl);
    }

    private function cache(): CacheRepository
    {
        $store = config('git.cache.store');

        return is_string($store) && $store !== '' ? Cache::store($store) : Cache::store();
    }
}
