<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Concerns;

trait HasRawPayload
{
    /**
     * The untouched provider payload this DTO was built from.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->raw;
    }
}
