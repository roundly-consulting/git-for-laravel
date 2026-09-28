<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Handles;

use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;

/**
 * The shape checks every value runs before it reaches a forge URL — in the handles, and
 * again in the drivers that build the URL, so the flat driver methods are covered too.
 *
 * A value is checked as given AND as every percent-decoding of it: libcurl and Guzzle
 * decode `%2e` and collapse dot segments before a request leaves, so `%2e%2e/victim` is
 * a traversal exactly like `../victim` (and `%252e` only one decode further away). The
 * drivers additionally {@see encode()} each segment, so what reaches the forge is the
 * literal value the caller passed — never a slash or a dot the stack decoded into one.
 *
 * @internal the handles' and drivers' building block; host code gets the same
 *           guarantees through them.
 */
final class PathGuard
{
    /** How many nested percent-encodings are unwrapped before a value is refused outright. */
    private const MAX_DECODES = 4;

    private const BITBUCKET_UUID = '/^\{[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\}$/i';

    /**
     * A repository path: one or more `/`-separated segments (`owner/name`, a GitLab
     * `group/sub/project`, or a bare GitLab project id), none of which can step out of it.
     */
    public static function repository(string $path): string
    {
        if (! self::segmentsAreSafe($path, rejectWhitespace: true)) {
            throw OutOfScopeException::repositoryPath($path);
        }

        return $path;
    }

    /** A file path inside the repository — spaces allowed, traversal not. */
    public static function file(string $path): string
    {
        if (! self::segmentsAreSafe($path, rejectWhitespace: false)) {
            throw OutOfScopeException::filePath($path);
        }

        return $path;
    }

    /** A git ref — a sha, a branch or a tag; slashes allowed (`release/1.0`), traversal not. */
    public static function ref(string $label, string $value): string
    {
        if (! self::segmentsAreSafe($value, rejectWhitespace: true)) {
            throw OutOfScopeException::identifier($label, $value);
        }

        return $value;
    }

    /** An account login or organization name: exactly one safe segment. */
    public static function segment(string $label, string $value): string
    {
        if (! self::segmentsAreSafe($value, rejectWhitespace: true) || str_contains(self::decoded($value), '/')) {
            throw OutOfScopeException::identifier($label, $value);
        }

        return $value;
    }

    /** A forge-issued numeric id. */
    public static function numeric(string $label, string $value): string
    {
        if ($value === '' || ! ctype_digit($value)) {
            throw OutOfScopeException::identifier($label, $value);
        }

        return $value;
    }

    /**
     * A webhook id in the shape the forge issues it: numeric on GitHub and GitLab, a
     * braced `{uuid}` on Bitbucket. Anything else is refused — the id lands in a DELETE URL.
     */
    public static function webhookId(ProviderName $provider, string $id): string
    {
        return match ($provider) {
            ProviderName::Bitbucket => preg_match(self::BITBUCKET_UUID, $id) === 1
                ? $id
                : throw OutOfScopeException::identifier('webhook id', $id),
            default => self::numeric('webhook id', $id),
        };
    }

    /**
     * Percent-encode each `/`-separated segment, keeping the separators.
     *
     * Applied by the drivers to a value that already passed a guard above, so a `%`, a
     * space or a `{` in a name reaches the forge as that character rather than as
     * whatever the HTTP stack (or Laravel's URI-template expansion) makes of it.
     */
    public static function encode(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /**
     * Whether a value — and every decoding of it the HTTP stack could perform — stays
     * inside its scope.
     */
    private static function segmentsAreSafe(string $path, bool $rejectWhitespace): bool
    {
        if ($path === '' || ($rejectWhitespace && preg_match('/\s/', $path) === 1)) {
            return false;
        }

        $candidate = $path;

        for ($decodes = 0; $decodes <= self::MAX_DECODES; $decodes++) {
            if (! self::isSafeLiteral($candidate)) {
                return false;
            }

            $decoded = rawurldecode($candidate);

            if ($decoded === $candidate) {
                return true;
            }

            $candidate = $decoded;
        }

        // Still decoding after this many layers: no legitimate name is built like that.
        return false;
    }

    /** The value with every layer of percent-encoding removed (bounded, like the check above). */
    private static function decoded(string $value): string
    {
        for ($decodes = 0; $decodes <= self::MAX_DECODES; $decodes++) {
            $decoded = rawurldecode($value);

            if ($decoded === $value) {
                break;
            }

            $value = $decoded;
        }

        return $value;
    }

    private static function isSafeLiteral(string $path): bool
    {
        if ($path === '' || strpbrk($path, "?#\\\0") !== false) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
