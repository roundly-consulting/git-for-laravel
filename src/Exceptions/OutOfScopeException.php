<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Exceptions;

use InvalidArgumentException;
use RoundlyConsulting\Git\Enums\ProviderName;

/**
 * An argument that would point a scoped handle at something outside its scope.
 *
 * Every repository path, file path and identifier a handle takes is interpolated into a
 * forge URL, so a `..` segment, an empty segment or a stray `?`/`#` does not fail — it
 * addresses a DIFFERENT resource than the one the handle was scoped to. The handles
 * refuse those shapes instead, and refuse a repository that belongs to another provider.
 */
final class OutOfScopeException extends InvalidArgumentException
{
    public static function repositoryPath(string $path): self
    {
        return new self(
            "[{$path}] is not a repository path: expected slash-separated segments (owner/name) with no empty, '.' or '..' segment and no '?', '#', '\\' or whitespace."
        );
    }

    public static function filePath(string $path): self
    {
        return new self(
            "[{$path}] is not a file path inside the repository: it must be non-empty, with no empty, '.' or '..' segment and no '?', '#' or '\\'."
        );
    }

    public static function identifier(string $label, string $value): self
    {
        return new self("[{$value}] is not a valid {$label}.");
    }

    public static function pullRequestNumber(int $number): self
    {
        return new self("Pull request numbers start at 1; got [{$number}].");
    }

    public static function foreignRepository(string $path, ProviderName $owner, ProviderName $handle): self
    {
        return new self(
            "The repository [{$path}] belongs to {$owner->label()}, not to this {$handle->label()} provider."
        );
    }
}
