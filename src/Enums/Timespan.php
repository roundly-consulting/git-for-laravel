<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Enums;

enum Timespan: string
{
    case Second = 'second';
    case Minute = 'minute';
    case Hour = 'hour';
    case Day = 'day';

    public function seconds(): int
    {
        return match ($this) {
            self::Second => 1,
            self::Minute => 60,
            self::Hour => 3600,
            self::Day => 86400,
        };
    }

    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
