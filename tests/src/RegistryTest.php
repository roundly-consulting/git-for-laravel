<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Facades\Registry;
use RoundlyConsulting\Git\Providers\Github;

it('returns github instance using facade', function () {
    $credentials = new Token(
        new SensitiveParameterValue('d9297f39-3716-44e6-9d58-988edbfd1cfa'),
    );

    $instance = Registry::github($credentials);

    expect($instance)->toBeInstanceOf(Github::class);
});

it('returns github instance using facade and provider method', function () {
    $credentials = new Token(
        new SensitiveParameterValue('d9297f39-3716-44e6-9d58-988edbfd1cfa'),
    );

    $instance = Registry::provider(Github::class, $credentials);

    expect($instance)->toBeInstanceOf(Github::class)
        ->isAuthenticated()->toBeTrue();
});

it('returns github instance using facade and provider method without credentials', function () {
    $instance = Registry::provider(Github::class);

    expect($instance)->toBeInstanceOf(Github::class)
        ->isAuthenticated()->toBeFalse();
});
