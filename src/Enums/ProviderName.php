<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Enums;

use RoundlyConsulting\Git\Providers\BaseProvider;
use RoundlyConsulting\Git\Providers\Bitbucket;
use RoundlyConsulting\Git\Providers\Github;
use RoundlyConsulting\Git\Providers\Gitlab;

enum ProviderName: string
{
    case Github = 'github';
    case Gitlab = 'gitlab';
    case Bitbucket = 'bitbucket';

    public function key(): string
    {
        return $this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Github => 'GitHub',
            self::Gitlab => 'GitLab',
            self::Bitbucket => 'Bitbucket',
        };
    }

    /** @return class-string<BaseProvider> */
    public function providerClass(): string
    {
        return match ($this) {
            self::Github => Github::class,
            self::Gitlab => Gitlab::class,
            self::Bitbucket => Bitbucket::class,
        };
    }

    public function apiBaseUrl(): string
    {
        return match ($this) {
            self::Github => 'https://api.github.com',
            self::Gitlab => 'https://gitlab.com',
            self::Bitbucket => 'https://api.bitbucket.org',
        };
    }
}
