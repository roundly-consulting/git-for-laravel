<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Http;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
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
        return (bool) config('git.cache.enabled', false);
    }

    public function key(string $url, #[SensitiveParameter] ?string $token): string
    {
        $identity = hash('sha256', (string) $token);

        return "git:cache:{$this->provider}:".hash('sha256', $url).":{$identity}";
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
            (int) config('git.cache.ttl', 3600),
        );
    }

    private function store(): Repository
    {
        $store = config('git.cache.store');

        return Cache::store(is_string($store) ? $store : null);
    }
}
