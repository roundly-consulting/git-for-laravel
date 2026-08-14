<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Dto\Credentials\OauthToken;
use SensitiveParameter;

/**
 * An OAuth credential was exchanged for a fresh access token.
 *
 * This event exists because of what happens when the provider ROTATES the refresh token
 * on the way: the new one lands in the token cache, whose lifetime is the ACCESS token's
 * — an hour, typically. When that entry expires, the only refresh token left anywhere is
 * the one the host application stored, which the provider invalidated at rotation. The
 * connection then breaks permanently, and nothing before this event told the host that
 * the value it holds went stale.
 *
 * Listen and persist {@see $refreshToken} whenever {@see rotated()} is true:
 *
 * ```php
 * Event::listen(OauthTokenRefreshed::class, function (OauthTokenRefreshed $event) {
 *     if ($event->rotated()) {
 *         $connection->update(['refresh_token' => $event->refreshToken]);
 *     }
 * });
 * ```
 *
 * The tokens are carried in the clear because a listener has to be able to store them;
 * they are `#[SensitiveParameter]` at the constructor so a stack trace does not print
 * them, and this event must never be logged whole.
 */
final class OauthTokenRefreshed
{
    use Dispatchable;

    public function __construct(
        /** The credential the refresh was performed for — carries the client and token URL. */
        public readonly OauthToken $credentials,
        #[SensitiveParameter] public readonly string $accessToken,
        /** The refresh token to use NEXT time: the rotated one, or the unchanged original. */
        #[SensitiveParameter] public readonly string $refreshToken,
        public readonly Carbon $expiresAt,
    ) {}

    /** Whether the provider handed back a NEW refresh token, invalidating the old one. */
    public function rotated(): bool
    {
        return $this->refreshToken !== $this->credentials->refreshToken;
    }
}
