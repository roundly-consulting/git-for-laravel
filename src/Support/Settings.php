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
 * booleans, and a malformed integer, an unknown enum value or a blank string fails loudly
 * naming the key. Only an absent (null) value takes the default.
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
     * An optional integer: null leaves the feature off, anything else must be an integer
     * within range.
     *
     * @throws InvalidConfigurationException
     */
    public static function optionalInteger(string $key, mixed $value, int $min, int $max): ?int
    {
        return $value === null ? null : self::integer($key, $value, $min, $max, $min);
    }

    /**
     * A string setting: the default when null, otherwise a non-empty string.
     *
     * @throws InvalidConfigurationException
     */
    public static function string(string $key, mixed $value, string $default): string
    {
        if ($value === null) {
            return $default;
        }

        if (! is_string($value) || trim($value) === '') {
            throw InvalidConfigurationException::notAString($key, $value);
        }

        return $value;
    }

    /**
     * An optional string setting (a cache store, a log channel): null stays null — the
     * framework default — and anything else must be a non-empty string.
     *
     * @throws InvalidConfigurationException
     */
    public static function optionalString(string $key, mixed $value): ?string
    {
        return $value === null ? null : self::string($key, $value, '');
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
