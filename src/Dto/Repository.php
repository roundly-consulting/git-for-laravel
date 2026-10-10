<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Concerns\HasRawPayload;
use RoundlyConsulting\Git\Enums\ProviderName;

final readonly class Repository extends Dto
{
    use HasRawPayload;

    /**
     * @param  array<string, mixed>  $raw
     * @param  bool|null  $private  whether it is not public — GitLab's `internal` counts as private, as
     *                              on GitHub; null when the payload does not say
     * @param  string|null  $language  the forge's main language for it; null on GitLab, which does not say
     * @param  string|null  $webUrl  its page in a browser
     */
    public function __construct(
        public ProviderName $provider,
        public string $id,
        public string $path,
        public string $name,
        public ?string $description,
        public string $defaultBranch,
        public Owner $owner,
        public Carbon $createdAt,
        public Carbon $lastActivityAt,
        public array $raw = [],
        public ?bool $private = null,
        public ?string $language = null,
        public ?string $webUrl = null,
    ) {}
}
