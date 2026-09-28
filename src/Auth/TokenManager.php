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
use RoundlyConsulting\Git\Events\OauthTokenRefreshed;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use SensitiveParameter;

/**
 * Mints and caches expiring access tokens for GitHub App installations and
 * OAuth credentials, refreshing them transparently when they lapse.
 *
 * @internal host code reads a live token off the credential itself —
 *           `Git::credentials(ProviderName::Github)?->accessToken()` for a refreshable one.
 */
final class TokenManager
{
    /** Seconds shaved off the real expiry so a token is never used at the edge. */
    private const SAFETY_MARGIN = 60;

    public function installationToken(GithubAppToken $cred): string
    {
        // A scope that names no repository is a MISTAKE, not a request for a wide token:
        // GitHub reads a body with no selector as "every repository this installation can
        // reach", so a caller whose repository list came back empty would receive an
        // account-wide credential and no signal that anything went wrong. `scope === null`
        // still means "wide, deliberately" — that is the connection-wide read path.
        if ($cred->scope !== null && $cred->scope->isEmpty()) {
            throw InvalidCredentialsException::unscopedInstallationToken();
        }

        $key = $this->installationCacheKey($cred);

        /** @var array{token: string, expires_at: int}|null $cached */
        $cached = $this->cache()->get($key);

        if (is_array($cached) && $cached['expires_at'] > time()) {
            return $cached['token'];
        }

        $jwt = (new GithubAppJwt($cred->appId, $cred->privateKey))->issue();

        $response = Http::asJson()
            ->acceptJson()
            ->withToken($jwt)
            ->post(
                rtrim($cred->baseUrl(), '/')."/app/installations/{$cred->installationId}/access_tokens",
                $cred->scope?->toPayload() ?? [],
            );

        // The two failure modes a SCOPED mint adds, surfaced as the exception every
        // consumer of this package already catches. Letting `throw()` raise a raw
        // RequestException instead means a caller has to read a status code out of an
        // HTTP exception to tell "the app was uninstalled" from "the network blipped".
        if ($response->status() === 404) {
            throw InvalidCredentialsException::installationUnavailable(
                $cred->installationId,
                'it no longer exists — the app was uninstalled, or this id belongs to another app.',
            );
        }

        if ($response->status() === 422) {
            throw InvalidCredentialsException::installationUnavailable(
                $cred->installationId,
                'the requested scope was refused — a repository is outside the installation, or a permission was never granted.',
            );
        }

        // 403 is deliberately NOT mapped here. GitHub answers 403 for a primary or
        // secondary rate limit as well as for a suspended installation, and consumers
        // treat InvalidCredentialsException as "this connection is broken, a human must
        // reconnect" — so mapping it would let one throttled minute permanently break a
        // working connection. It falls through to `throw()`, i.e. a RequestException,
        // which reads as retryable.

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

        /** @var array{token: string, expires_at: int, refresh_token?: string}|null $cached */
        $cached = $this->cache()->get($key);

        if (is_array($cached) && $cached['expires_at'] > time()) {
            return $cached['token'];
        }

        if ($cached === null
            && $cred->expiresAt !== null
            && $cred->expiresAt->getTimestamp() - self::SAFETY_MARGIN > time()) {
            return $cred->accessTokenValue;
        }

        // A rotation the caller has not caught up with yet. The entry under THIS key was
        // written by a refresh that rotated, so the refresh token the credential still
        // carries is the one the provider invalidated; the live one is in the entry.
        //
        // Reachable whenever an entry outlives the token inside it — an access token that
        // arrives already expired (or inside the safety margin) is stored with a floor TTL
        // and re-refreshed on the next call. It is a guard, NOT the fix for rotation:
        // the entry dies with the access token, so once it is gone the only copy left is
        // the host's. {@see OauthTokenRefreshed} is what keeps that copy current.
        $refreshToken = is_array($cached) && is_string($cached['refresh_token'] ?? null) && $cached['refresh_token'] !== ''
            ? $cached['refresh_token']
            : $cred->refreshToken;

        return $this->refreshOauth($cred, $key, $refreshToken);
    }

    private function refreshOauth(OauthToken $cred, string $key, #[SensitiveParameter] string $presentedRefreshToken): string
    {
        $response = Http::asForm()
            ->acceptJson()
            ->post($cred->tokenUrl, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $presentedRefreshToken,
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
        $refreshToken = is_string($rotated) && $rotated !== '' ? $rotated : $presentedRefreshToken;

        $this->store($key, $token, $expiresTimestamp, $refreshToken);

        // A rotated refresh token re-keys the cache so the next lookup hits.
        if ($refreshToken !== $presentedRefreshToken) {
            $this->store($this->oauthCacheKey($refreshToken), $token, $expiresTimestamp, $refreshToken);
        }

        // The cache is not durable enough to be the only home for a rotated refresh
        // token: its entry expires with the ACCESS token, and after that the host's
        // stored value is the one the provider already invalidated. This is the only
        // notification a host gets that the value it persisted has to change.
        OauthTokenRefreshed::dispatch(
            $cred,
            $token,
            $refreshToken,
            Carbon::createFromTimestamp($expiresTimestamp),
        );

        return $token;
    }

    /**
     * The cache key for an installation token.
     *
     * Three things identify a minted token, and every one of them has to be in the key:
     *
     * - The HOST. App and installation ids are numeric and per-host, so app 123 /
     *   installation 999 on github.com and the same pair on a GitHub Enterprise instance
     *   are unrelated credentials that would otherwise share one entry — and the cache is
     *   read before any HTTP call, so the second host would be served the first's token.
     *   Digested rather than embedded so a host with a `:` in it cannot forge a segment.
     * - app + installation, already proven numeric by {@see GithubAppToken}, so neither
     *   can smuggle a `:` into the key either.
     * - The SCOPE. Without it a repository-scoped mint would be served the
     *   installation-wide token a previous caller cached — silently handing back a
     *   credential for every repository in the installation, which is exactly the property
     *   scoping exists to remove.
     */
    private function installationCacheKey(GithubAppToken $cred): string
    {
        $key = 'git:app:'.(new Digest)->hex($cred->baseUrl()).':'.$cred->appId.':'.$cred->installationId;

        return $cred->scope === null ? $key : $key.':'.$cred->scope->digest();
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
