<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

final readonly class Feature extends Dto
{
    public const ListRepositories = 'repositories';

    public const FindRepository = 'repository';

    public const ListCommits = 'commits';

    public const FindCommits = 'commit';

    public const ListRepositoryBranches = 'branches';

    public function __construct(
        public string $id,
        public string $description,
    ) {}

    public static function listRepositories(): self
    {
        return new self(
            id: self::ListRepositories,
            description: 'List of all repositories accessible by credentials.'
        );
    }

    public static function findRepository(): self
    {
        return new self(
            id: self::FindRepository,
            description: 'Get details of specific repository by name, accessible by credentials.'
        );
    }

    public static function listCommits(): self
    {
        return new self(
            id: self::ListCommits,
            description: 'List of all branch commits accessible by credentials.'
        );
    }

    public static function findCommit(): self
    {
        return new self(
            id: self::FindCommits,
            description: 'Get details of specific commit by sha hash, accessible by credentials.'
        );
    }

    public static function listRepositoryBranches(): self
    {
        return new self(
            id: self::ListRepositoryBranches,
            description: 'List of all repository branches.'
        );
    }
}
