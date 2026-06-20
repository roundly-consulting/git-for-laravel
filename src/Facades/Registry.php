<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin \RoundlyConsulting\Git\Registry
 *
 * @see \RoundlyConsulting\Git\Registry
 */
class Registry extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \RoundlyConsulting\Git\Registry::class;
    }
}
