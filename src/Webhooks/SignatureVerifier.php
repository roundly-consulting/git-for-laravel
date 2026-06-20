<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Webhooks;

use Illuminate\Http\Request;
use RoundlyConsulting\Git\Enums\ProviderName;

final class SignatureVerifier
{
    public function verify(ProviderName $provider, Request $request): bool
    {
        $secret = config("git.providers.{$provider->key()}.webhook_secret");

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        return match ($provider) {
            ProviderName::Github => $this->verifyGithub($request, $secret),
            ProviderName::Gitlab => $this->verifyGitlab($request, $secret),
            ProviderName::Bitbucket => $this->verifyBitbucket($request, $secret),
        };
    }

    private function verifyGithub(Request $request, string $secret): bool
    {
        $signature = $request->header('X-Hub-Signature-256');

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    private function verifyGitlab(Request $request, string $secret): bool
    {
        $token = $request->header('X-Gitlab-Token');

        if (! is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($secret, $token);
    }

    private function verifyBitbucket(Request $request, string $secret): bool
    {
        // Bitbucket Cloud signs with X-Hub-Signature (sha256) when a secret is configured.
        $signature = $request->header('X-Hub-Signature');

        if (! is_string($signature) || $signature === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
