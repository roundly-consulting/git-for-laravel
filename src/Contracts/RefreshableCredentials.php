<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Contracts;

interface RefreshableCredentials
{
    /**
     * Return a currently-valid access token, minting/refreshing and caching as
     * needed.
     */
    public function accessToken(): string;
}
