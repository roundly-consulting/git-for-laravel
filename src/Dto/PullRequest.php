<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Carbon\CarbonInterface;

final readonly class PullRequest extends Dto
{
    public function __construct(
        public string $id,
        public int $number,
        public string $title,
        public ?string $body,
        public string $state,
        public string $sourceBranch,
        public string $targetBranch,
        public ?Author $author,
        public ?string $url,
        public CarbonInterface $createdAt,
    ) {}
}
