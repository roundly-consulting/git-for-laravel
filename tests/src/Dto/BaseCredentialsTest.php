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

it('redacts the secret when serialized to array', function () {
    $credentials = new BaseCredentials(
        new SensitiveParameterValue('my-value'),
    );

    expect($credentials->toArray())
        ->toBe(['credentials' => '••••'])
        ->and(json_encode($credentials))->not->toContain('my-value');
});

it('serializes null credentials as null', function () {
    expect((new BaseCredentials)->toArray())->toBe(['credentials' => null]);
});
