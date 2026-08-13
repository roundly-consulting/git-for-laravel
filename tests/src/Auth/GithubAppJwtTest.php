<?php

declare(strict_types=1);

use RoundlyConsulting\Crypto\Codec\Base64Url;
use RoundlyConsulting\Crypto\Jose\Jws;
use RoundlyConsulting\Crypto\Signature\Algorithm;
use RoundlyConsulting\Crypto\Signature\InvalidSignatureException;
use RoundlyConsulting\Crypto\Signature\Key\RsaKey;
use RoundlyConsulting\Crypto\Signature\Rs;
use RoundlyConsulting\Git\Auth\GithubAppJwt;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;

/** @return array<string, mixed> */
function decodeSegment(string $segment): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(Base64Url::decode($segment), true);

    return $decoded;
}

it('issues an rs256 jws that verifies against the matching public key', function (): void {
    [$privateKey, $publicKey] = generateRsaKeypair();

    $jwt = (new GithubAppJwt('123456', $privateKey))->issue(540);

    [$header, $claims] = explode('.', $jwt);

    $decodedHeader = decodeSegment($header);
    $decodedClaims = decodeSegment($claims);

    expect($decodedHeader['alg'])->toBe('RS256')
        ->and($decodedHeader['typ'])->toBe('JWT')
        ->and($decodedClaims['iss'])->toBe('123456')
        ->and($decodedClaims['exp'] - $decodedClaims['iat'])->toBe(600);

    // The token GitHub receives must be a valid RS256 JWS: pinned alg, real signature.
    $verified = (new Jws)->verify($jwt, new Rs(RsaKey::public($publicKey)), Algorithm::RS256);

    expect($verified->get('iss'))->toBe('123456');
});

it('rejects the token when verified with an unrelated public key', function (): void {
    [$privateKey] = generateRsaKeypair();
    [, $otherPublicKey] = generateRsaKeypair();

    $jwt = (new GithubAppJwt('123456', $privateKey))->issue();

    expect(fn () => (new Jws)->verify($jwt, new Rs(RsaKey::public($otherPublicKey)), Algorithm::RS256))
        ->toThrow(InvalidSignatureException::class);
});

it('backdates iat by a minute to absorb clock skew', function (): void {
    [$privateKey] = generateRsaKeypair();

    $before = time();
    $jwt = (new GithubAppJwt('123456', $privateKey))->issue(300);
    $claims = decodeSegment(explode('.', $jwt)[1]);

    expect($claims['iat'])->toBeLessThanOrEqual($before - 60)
        ->and($claims['exp'] - $claims['iat'])->toBe(360);
});

it('throws on a malformed private key', function (): void {
    expect(fn () => (new GithubAppJwt('123', 'not-a-key'))->issue())
        ->toThrow(InvalidCredentialsException::class);
});

it('throws on a public key supplied where a private key is required', function (): void {
    [, $publicKey] = generateRsaKeypair();

    expect(fn () => (new GithubAppJwt('123', $publicKey))->issue())
        ->toThrow(InvalidCredentialsException::class);
});

it('surfaces a crypto failure during signing as an invalid-credentials exception', function (): void {
    [$privateKey] = generateRsaKeypair();

    // An app id that is not valid UTF-8 cannot be encoded into the JWS payload;
    // crypto raises a MalformedTokenException and the boundary translates it.
    expect(fn () => (new GithubAppJwt("\xB1\x31", $privateKey))->issue())
        ->toThrow(InvalidCredentialsException::class, 'signing failed.');
});

it('reads a private key from a file path', function (): void {
    [$privateKey] = generateRsaKeypair();
    $path = (string) tempnam(sys_get_temp_dir(), 'pem');
    file_put_contents($path, $privateKey);

    $jwt = (new GithubAppJwt('123', $path))->issue();

    expect(explode('.', $jwt))->toHaveCount(3);

    unlink($path);
});
