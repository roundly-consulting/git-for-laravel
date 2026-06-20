<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Tests\testable\BaseCredentials;

it('returns name of credentials from class name', function () {
    $credentials = new BaseCredentials;

    expect($credentials)->name()->toBe('Base Credentials');
});

it('creates credentials instance using static method', function () {
    $credentials = BaseCredentials::from('test');

    expect($credentials)->credentials->getValue()->toBe('test');
});

it('checks whethere credentials are of type', function () {
    $credentials = new BaseCredentials;

    expect($credentials)
        ->is(BaseCredentials::class)->toBeTrue()
        ->isNot(BaseCredentials::class)->toBeFalse();
});

it('uses sensitive parameter value to store credentials but returns correct value when used with toArray', function () {
    $credentials = new BaseCredentials(
        new SensitiveParameterValue('my-value'),
    );

    expect($credentials->toArray())
        ->toBeArray()
        ->toHaveLength(1)
        ->toBe([
            'credentials' => 'my-value',
        ]);
});
