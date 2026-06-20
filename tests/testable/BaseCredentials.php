<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Tests\testable;

use RoundlyConsulting\Git\Dto\Credentials\BuildsFromSecret;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;

final readonly class BaseCredentials extends Credentials
{
    use BuildsFromSecret;
}
