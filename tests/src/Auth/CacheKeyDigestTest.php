<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\Input\InstallationTokenScope;
use RoundlyConsulting\Git\Http\ConditionalCache;

/**
 * The proving test for the `hash('sha256', …)` → `Crypto\Hash\Digest` refactor.
 *
 * `noLocalCryptoPrimitives` caught four raw `hash()` calls that git's own bespoke ban
 * had never covered — the bespoke list banned `hash_hmac` but not `hash`, so two of the
 * calls were digesting a *refresh token* with a primitive the package claimed, in its
 * arch test's own comment, to source only from crypto-for-laravel.
 *
 * The keys are a persisted contract: they name live cache entries, so a digest that
 * drifts silently strands every entry a deployed host already wrote (and, on the
 * rotation path, the token the next lookup needs). These vectors were frozen from the
 * pre-refactor implementation and are asserted byte-for-byte, so the refactor is pinned
 * as the no-op it claims to be rather than trusted to be one.
 */
it('derives a conditional-cache key byte-identically to the pre-refactor digest', function (): void {
    $cache = new ConditionalCache('github');

    expect($cache->key('https://api.github.com/repos/a/b', 'token-value'))
        ->toBe('git:cache:github:03c76211d35e1ef90ca423d9ab9d83737287fa2546bffc53dde251dde7f06ee5:e6c02a5742ea9d4de588eb9b9de7bed43dc17011552186bed3e98b2c5958ff4a');
});

/**
 * A null token must digest the empty string rather than short-circuit — the identity
 * segment stays a fixed-width digest, so an anonymous request can never collide with a
 * key whose token segment was simply absent.
 */
it('digests a null token as the empty string', function (): void {
    $cache = new ConditionalCache('github');

    expect($cache->key('https://api.github.com/repos/a/b', null))
        ->toBe('git:cache:github:03c76211d35e1ef90ca423d9ab9d83737287fa2546bffc53dde251dde7f06ee5:e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
});

/**
 * The credential itself must never appear in a key — the reason the digest is there at
 * all. Asserted separately from the frozen vectors above: those would still pass if the
 * digest were replaced by something that embedded the token AND matched the vector, and
 * this states the property rather than the value.
 */
it('never embeds the raw token in a cache key', function (): void {
    $key = (new ConditionalCache('github'))->key('https://api.github.com/repos/a/b', 'super-secret-token');

    expect($key)->not->toContain('super-secret-token')
        ->and($key)->not->toContain('https://api.github.com/repos/a/b');
});

it('freezes the installation-scope digest as a vector', function () {
    // A persisted contract like the keys above: a digest that drifts silently strands
    // every cached installation token a deployed host already wrote.
    $scope = new InstallationTokenScope(
        repositoryIds: ['40823311'],
        permissions: ['contents' => 'write', 'metadata' => 'read', 'pull_requests' => 'write'],
    );

    expect($scope->digest())->toBe(hash('sha256', json_encode([
        [40823311],
        [],
        ['contents' => 'write', 'metadata' => 'read', 'pull_requests' => 'write'],
    ], JSON_THROW_ON_ERROR)));
});
