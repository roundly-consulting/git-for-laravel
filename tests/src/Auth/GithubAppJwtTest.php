<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Auth\GithubAppJwt;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;

function generateRsaKeypair(): array
{
    $resource = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);

    openssl_pkey_export($resource, $privateKey);
    $details = openssl_pkey_get_details($resource);

    return [$privateKey, $details['key']];
}

function decodeSegment(string $segment): array
{
    return json_decode(base64_decode(strtr($segment, '-_', '+/'), true), true);
}

it('issues a verifiable RS256 jwt', function () {
    [$privateKey, $publicKey] = generateRsaKeypair();

    $jwt = (new GithubAppJwt('123456', $privateKey))->issue(540);

    [$header, $claims, $signature] = explode('.', $jwt);

    $decodedHeader = decodeSegment($header);
    $decodedClaims = decodeSegment($claims);

    expect($decodedHeader['alg'])->toBe('RS256')
        ->and($decodedHeader['typ'])->toBe('JWT')
        ->and($decodedClaims['iss'])->toBe('123456')
        ->and($decodedClaims['exp'] - $decodedClaims['iat'])->toBe(600);

    $verified = openssl_verify(
        "{$header}.{$claims}",
        base64_decode(strtr($signature, '-_', '+/'), true),
        $publicKey,
        OPENSSL_ALGO_SHA256,
    );

    expect($verified)->toBe(1);
});

it('throws on a malformed private key', function () {
    expect(fn () => (new GithubAppJwt('123', 'not-a-key'))->issue())
        ->toThrow(InvalidCredentialsException::class);
});

it('reads a private key from a file path', function () {
    [$privateKey] = generateRsaKeypair();
    $path = tempnam(sys_get_temp_dir(), 'pem');
    file_put_contents($path, $privateKey);

    $jwt = (new GithubAppJwt('123', $path))->issue();

    expect($jwt)->toContain('.');

    unlink($path);
});
