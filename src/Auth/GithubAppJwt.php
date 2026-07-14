<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Auth;

use RoundlyConsulting\Crypto\Exceptions\CryptoException;
use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Key\RsaKey;
use RoundlyConsulting\Crypto\Signature\Rs;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use SensitiveParameter;

/**
 * Mints the short-lived RS256 JWT GitHub Apps use to authenticate as the app
 * itself. The JOSE serialization and the RSA signature come from
 * crypto-for-laravel; what stays here is GitHub's claim contract (iat backdated
 * a minute for clock skew, exp, and the app id as issuer) and git's own key
 * resolution — a PEM string or a path to one.
 */
final class GithubAppJwt
{
    public function __construct(
        private readonly string $appId,
        #[SensitiveParameter] private readonly string $privateKey,
    ) {}

    /**
     * @throws InvalidCredentialsException when the private key is unusable or signing fails
     */
    public function issue(int $ttlSeconds = 540): string
    {
        $now = time();
        $signer = new Rs($this->key());

        try {
            return (new Jws)->sign(
                header: [],
                payload: [
                    // GitHub tolerates a small clock drift; backdating iat keeps a
                    // slightly fast clock from minting a not-yet-valid token.
                    'iat' => $now - 60,
                    'exp' => $now + $ttlSeconds,
                    'iss' => $this->appId,
                ],
                signer: $signer,
            );
        } catch (CryptoException) {
            throw InvalidCredentialsException::invalidKey('signing failed.');
        }
    }

    /**
     * @throws InvalidCredentialsException
     */
    private function key(): RsaKey
    {
        $pem = is_file($this->privateKey)
            ? (string) file_get_contents($this->privateKey)
            : $this->privateKey;

        try {
            return RsaKey::private($pem);
        } catch (CryptoException) {
            throw InvalidCredentialsException::invalidKey();
        }
    }
}
