<?php

declare(strict_types=1);

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

// Every cryptographic primitive comes from crypto-for-laravel — never a
// third-party JOSE/JWT library, and never a hand-rolled copy back inside this
// package. The webhook HMAC, the constant-time compare, and the GitHub App JWT's
// RSA signature must not be re-implemented here.
//
// NOTE: base64 is deliberately NOT banned package-wide. `Providers\Github` and
// `Providers\Gitlab` base64-encode/decode *file contents* — that is the REST
// APIs' own blob wire format, not crypto. Only the crypto namespaces are held to
// the codec rule, below.
arch('no crypto primitive is re-implemented locally')
    ->expect('RoundlyConsulting\Git')
    ->not->toUse([
        'hash_hmac',
        'hash_equals',
        'openssl_sign',
        'openssl_verify',
        'openssl_pkey_new',
        'openssl_pkey_get_private',
        'openssl_pkey_get_public',
        'openssl_pkey_get_details',
        'openssl_pkey_export',
        'random_bytes',
    ]);

// The crypto-bearing namespaces additionally get no base64 of their own: the
// base64url in the JWS signing path belongs to `Crypto\Codec\Base64Url`.
arch('the crypto namespaces hand-roll no codec')
    ->expect(['RoundlyConsulting\Git\Auth', 'RoundlyConsulting\Git\Webhooks'])
    ->not->toUse(['base64_encode', 'base64_decode']);

// Only crypto's PUBLIC surface is ours to use: whatever crypto tags `@internal`
// today or tomorrow, this package must not import it, so an internal refactor of
// crypto can never break git.
it('imports no crypto class tagged @internal', function (): void {
    $cryptoSrc = realpath(__DIR__.'/../vendor/roundly-consulting/crypto-for-laravel/src');

    expect($cryptoSrc)->toBeString();

    /** @var list<string> $internal */
    $internal = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) $cryptoSrc, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (! str_contains($contents, '@internal')) {
            continue;
        }

        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace) === 1) {
            $internal[] = trim($namespace[1]).'\\'.$file->getBasename('.php');
        }
    }

    // Sanity: crypto really does tag something internal (guards a silent no-op).
    expect($internal)->not->toBeEmpty();

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) realpath(__DIR__.'/../src'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        foreach ($internal as $class) {
            expect($contents)->not->toContain($class, "{$file->getPathname()} imports the internal crypto class {$class}");
        }
    }
});

arch('every source file declares strict types')
    ->expect('RoundlyConsulting\Git')
    ->toUseStrictTypes();
