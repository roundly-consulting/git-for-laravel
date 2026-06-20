<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Enums;

enum ResourceState: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Merged = 'merged';
    case Draft = 'draft';
    case Unknown = 'unknown';

    /**
     * Normalize any provider state string into the canonical case.
     *
     * Unmatched values resolve to {@see self::Unknown} rather than throwing;
     * the original string is always reachable through the DTO's `raw()`.
     */
    public static function fromProvider(ProviderName $provider, string $raw): self
    {
        $value = strtolower($raw);

        return match ($provider) {
            ProviderName::Github => match ($value) {
                'open' => self::Open,
                'closed' => self::Closed,
                'merged' => self::Merged,
                default => self::Unknown,
            },
            ProviderName::Gitlab => match ($value) {
                'opened' => self::Open,
                'closed', 'locked' => self::Closed,
                'merged' => self::Merged,
                default => self::Unknown,
            },
            ProviderName::Bitbucket => match ($value) {
                'open' => self::Open,
                'merged' => self::Merged,
                'declined', 'superseded' => self::Closed,
                default => self::Unknown,
            },
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
            self::Merged => 'Merged',
            self::Draft => 'Draft',
            self::Unknown => 'Unknown',
        };
    }
}
