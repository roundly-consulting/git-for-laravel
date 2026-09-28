<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Handles;

use RoundlyConsulting\Git\Exceptions\OutOfScopeException;

/**
 * The shape checks every handle runs before a value reaches a forge URL.
 *
 * @internal the handles' building block; host code gets the same guarantees through them.
 */
final class PathGuard
{
    /**
     * A repository path: one or more `/`-separated segments (`owner/name`, a GitLab
     * `group/sub/project`, or a bare GitLab project id), none of which can step out of it.
     */
    public static function repository(string $path): string
    {
        if (! self::segmentsAreSafe($path) || preg_match('/\s/', $path) === 1) {
            throw OutOfScopeException::repositoryPath($path);
        }

        return $path;
    }

    /** A file path inside the repository — spaces allowed, traversal not. */
    public static function file(string $path): string
    {
        if (! self::segmentsAreSafe($path)) {
            throw OutOfScopeException::filePath($path);
        }

        return $path;
    }

    /** A git ref — a sha, a branch or a tag; slashes allowed (`release/1.0`), traversal not. */
    public static function ref(string $label, string $value): string
    {
        if (! self::segmentsAreSafe($value) || preg_match('/\s/', $value) === 1) {
            throw OutOfScopeException::identifier($label, $value);
        }

        return $value;
    }

    /** An account login or organization name: exactly one safe segment. */
    public static function segment(string $label, string $value): string
    {
        if (str_contains($value, '/') || ! self::segmentsAreSafe($value) || preg_match('/\s/', $value) === 1) {
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

    private static function segmentsAreSafe(string $path): bool
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
