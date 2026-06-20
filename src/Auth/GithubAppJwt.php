<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Auth;

use OpenSSLAsymmetricKey;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use SensitiveParameter;

/**
 * Mints the short-lived RS256 JWT GitHub Apps use to authenticate as the app
 * itself, signed natively with openssl_sign — no third-party JWT library.
 */
final class GithubAppJwt
{
    public function __construct(
        private readonly string $appId,
        #[SensitiveParameter] private readonly string $privateKey,
    ) {}

    public function issue(int $ttlSeconds = 540): string
    {
        $now = time();

        $header = $this->b64(['alg' => 'RS256', 'typ' => 'JWT']);
        $claims = $this->b64([
            'iat' => $now - 60,
            'exp' => $now + $ttlSeconds,
            'iss' => $this->appId,
        ]);

        $signingInput = "{$header}.{$claims}";

        $signature = '';

        if (openssl_sign($signingInput, $signature, $this->privateKeyResource(), OPENSSL_ALGO_SHA256) !== true) {
            throw InvalidCredentialsException::invalidKey('signing failed.');
        }

        return "{$signingInput}.".$this->b64url($signature);
    }

    /** @param array<string, mixed> $payload */
    private function b64(array $payload): string
    {
        return $this->b64url((string) json_encode($payload));
    }

    private function b64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function privateKeyResource(): OpenSSLAsymmetricKey
    {
        $pem = is_file($this->privateKey)
            ? (string) file_get_contents($this->privateKey)
            : $this->privateKey;

        $key = openssl_pkey_get_private($pem);

        if ($key === false) {
            throw InvalidCredentialsException::invalidKey();
        }

        return $key;
    }
}
