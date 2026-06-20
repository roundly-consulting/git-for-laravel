<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Credentials;

use RoundlyConsulting\Git\Dto\Dto;
use SensitiveParameterValue;

abstract readonly class Credentials extends Dto
{
    public function __construct(
        public ?SensitiveParameterValue $credentials = null,
    ) {}

    public function name(): string
    {
        return str(class_basename($this))->headline()->toString();
    }

    public function is(string $type): bool
    {
        return $this instanceof $type;
    }

    public function isNot(string $type): bool
    {
        return ! $this->is($type);
    }

    /**
     * Redact the secret so credentials never serialize into logs or output.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'credentials' => $this->credentials !== null ? '••••' : null,
        ];
    }
}
