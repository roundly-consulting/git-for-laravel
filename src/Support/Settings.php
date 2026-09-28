<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Support;

use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Coerces a config VALUE the way `.env` delivers it.
 *
 * `env()` returns every value as a string — `GITHUB_TIMEOUT=45` is `"45"` and
 * `GIT_CACHE_ENABLED=off` is `"off"` — so an `is_int()` check silently ignores the first
 * and a `(bool)` cast reads the second as ON. Both go through package-toolkit's validator
 * instead: an integer string is an integer, `1/true/on/yes` and `0/false/off/no` are
 * booleans, and a malformed integer fails loudly naming the key.
 *
 * Takes the value rather than the key because most of git's settings live under a
 * runtime driver key (`git.providers.{github|gitlab|bitbucket}.…`) that callers have
 * already read as a section.
 *
 * @internal
 */
final class Settings
{
    /** @throws InvalidConfigurationException when present but not an integer in range */
    public static function integer(string $key, mixed $value, int $min, int $max, int $default): int
    {
        return Config::for([$key => $value])->intBetween($key, $min, $max, $default);
    }

    public static function boolean(string $key, mixed $value, bool $default): bool
    {
        return Config::for([$key => $value])->boolean($key, $default);
    }
}
