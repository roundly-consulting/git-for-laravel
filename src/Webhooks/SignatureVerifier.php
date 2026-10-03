<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Webhooks;

use Illuminate\Http\Request;
use RoundlyConsulting\Crypto\Hash\ConstantTime;
use RoundlyConsulting\Crypto\Hash\HashAlgorithm;
use RoundlyConsulting\Crypto\Hash\Hmac;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Support\Settings;
use SensitiveParameter;

/**
 * Verifies that an inbound webhook really came from the provider.
 *
 * The HMAC and the constant-time compare come from crypto-for-laravel; what
 * stays here is each provider's wire contract — which header carries the
 * signature, and how the expected value is framed.
 *
 * @internal host code verifies through `Git::verifyWebhook($provider, $request)`.
 */
final class SignatureVerifier
{
    public function verify(ProviderName $provider, Request $request): bool
    {
        $secret = Settings::filled(config("git.providers.{$provider->key()}.webhook_secret"));

        if ($secret === null) {
            return false;
        }

        return match ($provider) {
            ProviderName::Github => $this->verifyHubSignature($request, 'X-Hub-Signature-256', $secret),
            ProviderName::Gitlab => $this->verifyToken($request, $secret),
            // Bitbucket Cloud signs with X-Hub-Signature (sha256) when a secret is configured.
            ProviderName::Bitbucket => $this->verifyHubSignature($request, 'X-Hub-Signature', $secret),
        };
    }

    /**
     * GitHub- and Bitbucket-style HMAC: the header carries `sha256=<lowercase hex>`
     * over the RAW request body. The prefix and the hex encoding are part of the
     * wire contract, so the expected string is rebuilt byte-for-byte and compared
     * in constant time.
     */
    private function verifyHubSignature(Request $request, string $header, #[SensitiveParameter] string $secret): bool
    {
        $signature = $request->header($header);

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $expected = 'sha256='.(new Hmac(HashAlgorithm::Sha256))->signHex($request->getContent(), $secret);

        return ConstantTime::equals($expected, $signature);
    }

    /**
     * GitLab sends the shared secret itself in `X-Gitlab-Token` — compared in
     * constant time, never with a plain string compare.
     */
    private function verifyToken(Request $request, #[SensitiveParameter] string $secret): bool
    {
        $token = $request->header('X-Gitlab-Token');

        if (! is_string($token) || $token === '') {
            return false;
        }

        return ConstantTime::equals($secret, $token);
    }
}
