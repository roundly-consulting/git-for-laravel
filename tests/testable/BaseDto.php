<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Tests\testable;

use RoundlyConsulting\Git\Dto\Dto;

readonly class BaseDto extends Dto
{
    public function __construct(public mixed $value) {}
}
