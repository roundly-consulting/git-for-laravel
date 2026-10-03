<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Support;

use BackedEnum;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Coerces a config VALUE the way `.env` delivers it.
 *
 * `env()` returns every value as a string — `GITHUB_TIMEOUT=45` is `"45"` and
 * `GIT_CACHE_ENABLED=off` is `"off"` — so an `is_int()` check silently ignores the first
 * and a `(bool)` cast reads the second as ON. Both go through package-toolkit's validator
 * instead: an integer string is an integer, `1/true/on/yes` and `0/false/off/no` are
 * booleans, and a malformed integer or an unknown enum value fails loudly naming the key.
 * A value that is not set — null, or blank (`''` or whitespace, a host's `KEY=`) — takes
 * the default, or stays null for an optional setting.
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
        return Config::for([$key => $value])->integer($key, $default, min: $min, max: $max);
    }

    public static function boolean(string $key, mixed $value, bool $default): bool
    {
        return Config::for([$key => $value])->boolean($key, $default);
    }

    /**
     * An optional integer: not set (null or blank) leaves the feature off, anything else
     * must be an integer within range.
     *
     * @throws InvalidConfigurationException
     */
    public static function optionalInteger(string $key, mixed $value, int $min, int $max): ?int
    {
        return self::isUnset($value) ? null : self::integer($key, $value, $min, $max, $min);
    }

    /**
     * A string setting: the default when not set (null or blank), otherwise a string.
     *
     * @throws InvalidConfigurationException
     */
    public static function string(string $key, mixed $value, string $default): string
    {
        if (self::isUnset($value)) {
            return $default;
        }

        if (! is_string($value)) {
            throw InvalidConfigurationException::notAString($key, $value);
        }

        return $value;
    }

    /**
     * A route path prefix: the default when not set (null or blank), otherwise the string
     * with surrounding slashes and whitespace trimmed (`/hooks/` is `hooks`). A path of
     * only slashes would mount the route at the site root, so it throws.
     *
     * @throws InvalidConfigurationException
     */
    public static function routePath(string $key, mixed $value, string $default): string
    {
        $path = trim(self::string($key, $value, $default), " \t\n\r\0\x0B/");

        if ($path === '') {
            throw new InvalidConfigurationException("Configuration value [{$key}] must be a path below the site root, [".var_export($value, true).'] given.');
        }

        return $path;
    }

    /**
     * An optional string setting (a cache store, a log channel, an API URL): not set (null
     * or blank) is null — the framework or forge default — and anything else must be a
     * string.
     *
     * @throws InvalidConfigurationException
     */
    public static function optionalString(string $key, mixed $value): ?string
    {
        return self::isUnset($value) ? null : self::string($key, $value, '');
    }

    /**
     * A configured secret or identifier (a token, an app id, a webhook secret): the string
     * when one is set, null when not set (null or blank) — never a whitespace credential.
     */
    public static function filled(mixed $value): ?string
    {
        return is_string($value) && ! self::isUnset($value) ? $value : null;
    }

    /** Not set: null, or a blank string (`''` or whitespace — a host's `KEY=`). */
    public static function isUnset(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * @template TEnum of BackedEnum
     *
     * @param  class-string<TEnum>  $enum
     * @param  TEnum  $default
     * @return TEnum
     *
     * @throws InvalidConfigurationException
     */
    public static function enum(string $key, mixed $value, string $enum, BackedEnum $default): BackedEnum
    {
        return Config::for([$key => $value])->enum($key, $enum, $default);
    }
}
