<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto;

use RoundlyConsulting\Git\Enums\Feature;

final readonly class FeatureInfo extends Dto
{
    public function __construct(
        public Feature $id,
        public string $description,
    ) {}
}
