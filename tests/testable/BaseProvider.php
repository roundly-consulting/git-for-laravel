<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Tests\testable;

class BaseProvider extends \RoundlyConsulting\Git\Providers\BaseProvider
{
    protected function key(): string
    {
        return 'github';
    }

    protected function cloneBaseUrl(): string
    {
        return 'https://github.com';
    }
}
