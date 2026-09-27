<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Batch\Batch;
use RoundlyConsulting\Git\Providers\BaseProvider;
use RoundlyConsulting\Git\Providers\Bitbucket;
use RoundlyConsulting\Git\Providers\Github;
use RoundlyConsulting\Git\Providers\Gitlab;
use RoundlyConsulting\Git\Query\Query;
use RoundlyConsulting\Git\Registry;
use RoundlyConsulting\Testing\Arch\ArchPresets;

/**
 * The generic rules git hand-wrote are replaced by the presets below; the three that
 * have no preset equivalent — the package-wide primitive ban that deliberately spares
 * base64, and the `@internal` import rule — are kept as bespoke rules.
 */
ArchPresets::strictTypes('RoundlyConsulting\Git');

/**
 * Git ships no Eloquent model and no swappable-model config key, so neither
 * `swappableModelsAreNotFinal` nor `modelsResolveThroughSeam` is adopted: both halves
 * of the seam preset would be inert against a package with no seam to police.
 *
 * The extension points below are deliberate and documented:
 *  - `BaseProvider` / `Query` are abstract — the shape every forge driver extends;
 *  - `Github` / `Gitlab` / `Bitbucket` are the drivers a host subclasses to bend one
 *    forge's behaviour without forking the registry;
 *  - `Registry` is the manager those drivers are resolved from;
 *  - `Batch` is extended by the providers' own batch builders.
 *
 * The list goes through the `$ignoring` PARAMETER, not Pest's fluent `->ignoring()`. This
 * is the fleet's largest exemption set, which is exactly where the fluent form's two
 * silent costs bite hardest:
 *
 *  - it is NOT rot-checked. Eight `::class` constants that PHP resolves to strings at
 *    compile time, so a rename leaves a green exemption that silences nothing and a ban
 *    that quietly applies where nobody expects it;
 *  - it forfeits the shadow recovery. Pest matches exemptions by string PREFIX
 *    (pest-plugin-arch Blueprint.php:103), so `Batch::class` also silences `BatchError`
 *    and `BatchResult` — two classes nobody exempted. Through the parameter,
 *    `finalByDefault` re-checks them by reflection; both are final, so this is green
 *    today and stays a guard against either being opened later.
 */
ArchPresets::finalByDefault('RoundlyConsulting\Git', [
    BaseProvider::class,
    Github::class,
    Gitlab::class,
    Bitbucket::class,
    Query::class,
    Registry::class,
    Batch::class,
    RoundlyConsulting\Git\Facades\Registry::class,
]);

/**
 * The crypto-bearing namespaces get the full preset — every primitive AND base64.
 *
 * Scoped to `Auth` and `Webhooks` rather than applied package-wide and then relaxed
 * with `->ignoring(Github::class)`: Pest's `->ignoring()` is CLASS-scoped, so exempting
 * `Github` to permit its one legitimate base64 call would blind that whole class to all
 * nineteen primitives. Scoping the ban instead of the exemption keeps base64 banned
 * exactly where it means crypto, and allowed exactly where it means a REST blob.
 *
 * This preset no longer bans `hash_equals`, and git's bespoke list below drops it to
 * match: `hash_equals` IS PHP's constant-time compare rather than a copy of one, and
 * banning it pushes a caller toward `===` — a timing leak. `SignatureVerifier` routes
 * through `Crypto\Hash\ConstantTime` regardless.
 */
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Git\Auth');
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Git\Webhooks');

/**
 * The package-wide half the preset cannot express: every cryptographic primitive is
 * banned everywhere, but base64 is NOT — `Providers\Github` and `Providers\Gitlab`
 * base64-encode file *contents*, which is the REST APIs' own blob wire format rather
 * than a codec of ours. The namespaces where base64 would mean crypto are covered by
 * the two scoped presets above.
 */
arch('no crypto primitive is re-implemented locally')
    ->expect('RoundlyConsulting\Git')
    ->not->toUse([
        'hash',
        'hash_hmac',
        'hash_pbkdf2',
        'openssl_encrypt',
        'openssl_decrypt',
        'openssl_sign',
        'openssl_verify',
        'openssl_pkey_new',
        'openssl_pkey_get_private',
        'openssl_pkey_get_public',
        'openssl_pkey_get_details',
        'openssl_random_pseudo_bytes',
        'sodium_crypto_sign',
        'sodium_crypto_sign_verify_detached',
        'sodium_crypto_generichash',
        'random_bytes',
    ]);

/**
 * The Dependency Policy as a test — and the assertion that caught bug #6 fleet-wide,
 * where CI installed testbench into `require` before the suite ran. No `alsoAllow`:
 * git's `require` ships only php/illuminate/roundly, and the workflow installs test
 * tooling with `--dev`. If this goes red the graph is wrong; never widen it to quiet it.
 */
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../composer.json');

/**
 * Replaces the hand-written `['dd', 'dump', 'ray']` rule. The preset reads source
 * tokens rather than Pest's arch layer for a reason that applied exactly here: the arch
 * layer only sees a symbol that EXISTS, and the `ray()` debugger package is not in the dependency graph
 * by policy — so the old rule's `ray` was filtered out before it ran and could never
 * have failed. It also adds `var_dump`/`print_r`, which git never banned.
 */
ArchPresets::noDebuggingLeftovers();

/**
 * Bespoke, and kept: only crypto's PUBLIC surface is ours to use. Whatever crypto tags
 * `@internal` today or tomorrow, this package must not import it, so an internal
 * refactor of crypto can never break git. No preset expresses a cross-package
 * visibility rule.
 */
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
