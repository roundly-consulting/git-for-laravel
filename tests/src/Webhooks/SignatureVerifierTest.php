<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Webhooks\SignatureVerifier;

/**
 * The exact body and secret behind the frozen expected signature below. Both are
 * arbitrary but fixed — the point is that the wire string never drifts.
 */
const VECTOR_BODY = '{"action":"opened","number":42,"repository":{"full_name":"roundly-consulting/git-for-laravel"}}';

const VECTOR_SECRET = 'It is a secret to everybody';

/**
 * The `sha256=<hex>` value the pre-crypto implementation produced for
 * (VECTOR_BODY, VECTOR_SECRET). Hard-coded on purpose: if the HMAC, the digest
 * encoding, or the `sha256=` framing ever changes, every real GitHub and
 * Bitbucket webhook stops verifying — this test fails first.
 */
const VECTOR_SIGNATURE = 'sha256=921d93384790fc126d687486d44feacee8c445b8206d85d8f42e5ca55234d90c';

function webhookRequest(string $body, array $headers = []): Request
{
    $server = [];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.$name] = $value;
    }

    return Request::create('/git/webhooks/github', 'POST', [], [], [], $server, $body);
}

it('accepts the frozen github signature vector byte-for-byte', function (): void {
    config()->set('git.providers.github.webhook_secret', VECTOR_SECRET);

    $request = webhookRequest(VECTOR_BODY, ['X-Hub-Signature-256' => VECTOR_SIGNATURE]);

    expect((new SignatureVerifier)->verify(ProviderName::Github, $request))->toBeTrue();
});

it('accepts the same frozen vector on the bitbucket header', function (): void {
    config()->set('git.providers.bitbucket.webhook_secret', VECTOR_SECRET);

    $request = webhookRequest(VECTOR_BODY, ['X-Hub-Signature' => VECTOR_SIGNATURE]);

    expect((new SignatureVerifier)->verify(ProviderName::Bitbucket, $request))->toBeTrue();
});

it('hmacs the raw request body, not a re-encoded payload', function (): void {
    config()->set('git.providers.github.webhook_secret', VECTOR_SECRET);

    // Same JSON *value*, different bytes (whitespace + key order). A verifier that
    // re-serialized the decoded array would wrongly accept the frozen signature.
    $reencoded = json_encode(json_decode(VECTOR_BODY, true), JSON_PRETTY_PRINT);

    $request = webhookRequest((string) $reencoded, ['X-Hub-Signature-256' => VECTOR_SIGNATURE]);

    expect((new SignatureVerifier)->verify(ProviderName::Github, $request))->toBeFalse();
});

it('rejects a right signature over a different body', function (): void {
    config()->set('git.providers.github.webhook_secret', VECTOR_SECRET);

    $request = webhookRequest(VECTOR_BODY.' ', ['X-Hub-Signature-256' => VECTOR_SIGNATURE]);

    expect((new SignatureVerifier)->verify(ProviderName::Github, $request))->toBeFalse();
});

it('rejects a forged signature', function (): void {
    config()->set('git.providers.github.webhook_secret', VECTOR_SECRET);

    $request = webhookRequest(VECTOR_BODY, [
        'X-Hub-Signature-256' => 'sha256='.str_repeat('a', 64),
    ]);

    expect((new SignatureVerifier)->verify(ProviderName::Github, $request))->toBeFalse();
});

it('rejects a signature computed with the wrong secret', function (): void {
    config()->set('git.providers.github.webhook_secret', VECTOR_SECRET);

    $request = webhookRequest(VECTOR_BODY, [
        'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', VECTOR_BODY, 'wrong-secret'),
    ]);

    expect((new SignatureVerifier)->verify(ProviderName::Github, $request))->toBeFalse();
});

it('rejects a correct digest without the sha256= prefix', function (): void {
    config()->set('git.providers.github.webhook_secret', VECTOR_SECRET);

    $request = webhookRequest(VECTOR_BODY, [
        'X-Hub-Signature-256' => substr(VECTOR_SIGNATURE, 7),
    ]);

    expect((new SignatureVerifier)->verify(ProviderName::Github, $request))->toBeFalse();
});

it('rejects a missing signature header', function (): void {
    config()->set('git.providers.github.webhook_secret', VECTOR_SECRET);

    expect((new SignatureVerifier)->verify(ProviderName::Github, webhookRequest(VECTOR_BODY)))->toBeFalse();
});

it('rejects an empty signature header', function (): void {
    config()->set('git.providers.github.webhook_secret', VECTOR_SECRET);

    $request = webhookRequest(VECTOR_BODY, ['X-Hub-Signature-256' => '']);

    expect((new SignatureVerifier)->verify(ProviderName::Github, $request))->toBeFalse();
});

it('rejects a missing bitbucket signature header', function (): void {
    config()->set('git.providers.bitbucket.webhook_secret', VECTOR_SECRET);

    expect((new SignatureVerifier)->verify(ProviderName::Bitbucket, webhookRequest(VECTOR_BODY)))->toBeFalse();
});

it('rejects every provider when no secret is configured', function (ProviderName $provider): void {
    config()->set("git.providers.{$provider->key()}.webhook_secret", null);

    $request = webhookRequest(VECTOR_BODY, [
        'X-Hub-Signature-256' => VECTOR_SIGNATURE,
        'X-Hub-Signature' => VECTOR_SIGNATURE,
        'X-Gitlab-Token' => VECTOR_SECRET,
    ]);

    expect((new SignatureVerifier)->verify($provider, $request))->toBeFalse();
})->with(ProviderName::cases());

it('rejects every provider when the secret is an empty string', function (ProviderName $provider): void {
    config()->set("git.providers.{$provider->key()}.webhook_secret", '');

    $request = webhookRequest(VECTOR_BODY, [
        'X-Hub-Signature-256' => VECTOR_SIGNATURE,
        'X-Hub-Signature' => VECTOR_SIGNATURE,
        'X-Gitlab-Token' => VECTOR_SECRET,
    ]);

    expect((new SignatureVerifier)->verify($provider, $request))->toBeFalse();
})->with(ProviderName::cases());

it('accepts the gitlab shared token', function (): void {
    config()->set('git.providers.gitlab.webhook_secret', VECTOR_SECRET);

    $request = webhookRequest(VECTOR_BODY, ['X-Gitlab-Token' => VECTOR_SECRET]);

    expect((new SignatureVerifier)->verify(ProviderName::Gitlab, $request))->toBeTrue();
});

it('rejects a wrong gitlab token', function (): void {
    config()->set('git.providers.gitlab.webhook_secret', VECTOR_SECRET);

    $request = webhookRequest(VECTOR_BODY, ['X-Gitlab-Token' => 'It is a secret to everybody!']);

    expect((new SignatureVerifier)->verify(ProviderName::Gitlab, $request))->toBeFalse();
});

it('rejects a missing gitlab token', function (): void {
    config()->set('git.providers.gitlab.webhook_secret', VECTOR_SECRET);

    expect((new SignatureVerifier)->verify(ProviderName::Gitlab, webhookRequest(VECTOR_BODY)))->toBeFalse();
});

it('rejects an empty gitlab token', function (): void {
    config()->set('git.providers.gitlab.webhook_secret', VECTOR_SECRET);

    $request = webhookRequest(VECTOR_BODY, ['X-Gitlab-Token' => '']);

    expect((new SignatureVerifier)->verify(ProviderName::Gitlab, $request))->toBeFalse();
});
