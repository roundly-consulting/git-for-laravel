<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Mapping;

use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Issue;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Enums\ProviderName;

/**
 * Turns a raw provider payload into a canonical DTO and stores the raw array
 * on it. One implementation per provider, reused by both the providers and the
 * inbound-webhook accessors.
 */
interface ResourceMapper
{
    public function provider(): ProviderName;

    /** @param array<string, mixed> $raw */
    public function repository(array $raw): Repository;

    /** @param array<string, mixed> $raw */
    public function commit(array $raw): Commit;

    /** @param array<string, mixed> $raw */
    public function pullRequest(array $raw): PullRequest;

    /** @param array<string, mixed> $raw */
    public function issue(array $raw): Issue;

    /** @param array<string, mixed> $raw */
    public function release(array $raw): Release;

    /** @param array<string, mixed> $raw */
    public function tag(array $raw): Tag;
}
