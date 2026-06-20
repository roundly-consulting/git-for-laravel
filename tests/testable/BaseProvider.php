<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Tests\testable;

use RoundlyConsulting\Git\Mapping\GithubMapper;
use RoundlyConsulting\Git\Mapping\ResourceMapper;

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

    protected function mapper(): ResourceMapper
    {
        return resolve(GithubMapper::class);
    }
}
