<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\ProviderName;

/**
 * A GitHub App installation — the app authorized on one account (org or user).
 *
 * The `id` is NOT a credential: it names an installation, and minting against it
 * requires the app's private key. `repositorySelection` is the one field worth
 * surfacing to a human: `all` means the installation reaches every repository the
 * account owns, now and in the future, and no scoping we do at mint time can shrink
 * what a later `all` install re-widens.
 */
final readonly class Installation extends Dto
{
    use HasRawPayload;

    /**
     * @param  array<string, string>  $permissions
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public ProviderName $provider,
        public string $id,
        public string $accountLogin,
        public string $accountType,
        public string $repositorySelection,
        public array $permissions = [],
        public ?Carbon $suspendedAt = null,
        public array $raw = [],
    ) {}

    public function isSuspended(): bool
    {
        return $this->suspendedAt !== null;
    }

    public function reachesEveryRepository(): bool
    {
        return $this->repositorySelection === 'all';
    }
}
