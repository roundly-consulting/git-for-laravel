<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Http;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use RoundlyConsulting\Crypto\Hash\Digest;
use RoundlyConsulting\Git\Support\Settings;
use RoundlyConsulting\PackageToolkit\Support\Config;
use SensitiveParameter;

/**
 * Stores upstream ETags + bodies so conditional requests can be answered from
 * Laravel's cache when the provider replies with 304 Not Modified.
 */
final class ConditionalCache
{
    public function __construct(
        private readonly string $provider,
    ) {}

    public function enabled(): bool
    {
        return Config::boolean('git.cache.enabled');
    }

    /**
     * The token is digested rather than used directly so a credential never lands in a
     * cache key, and `Digest` is crypto's deterministic-digest primitive — the one its
     * docblock names cache keys as the use case for. It is `hash('sha256', …)` verbatim,
     * so the keys are byte-identical to the ones this method returned before.
     */
    public function key(string $url, #[SensitiveParameter] ?string $token): string
    {
        $digest = new Digest;

        $identity = $digest->hex((string) $token);

        return "git:cache:{$this->provider}:".$digest->hex($url).":{$identity}";
    }

    /** @return array{etag: string, body: string}|null */
    public function get(string $cacheKey): ?array
    {
        /** @var array{etag: string, body: string}|null $entry */
        $entry = $this->store()->get($cacheKey);

        return $entry;
    }

    public function put(string $cacheKey, string $etag, string $body): void
    {
        $this->store()->put(
            $cacheKey,
            ['etag' => $etag, 'body' => $body],
            Settings::integer('git.cache.ttl', config('git.cache.ttl'), 1, PHP_INT_MAX, 3600),
        );
    }

    private function store(): Repository
    {
        return Cache::store(Settings::optionalString('git.cache.store', config('git.cache.store')));
    }
}
