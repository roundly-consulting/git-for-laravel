<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\Credentials\Password;

it('returns credentials from string value', function () {
    $password = new Password(
        credentials: new SensitiveParameterValue('{"login":"john","password":"zer0day"}'),
    );

    expect($password->credentials())->toBe([
        'login' => 'john',
        'password' => 'zer0day',
    ]);
});

it('returns login', function () {
    $password = new Password(
        credentials: new SensitiveParameterValue('{"login":"john","password":"zer0day"}'),
    );

    expect($password->login())->toBe('john');
});

it('returns password', function () {
    $password = new Password(
        credentials: new SensitiveParameterValue('{"login":"john","password":"zer0day"}'),
    );

    expect($password->password())->toBe('zer0day');
});

it('returns credentials value as array when using toArray method', function () {
    $password = new Password(
        credentials: new SensitiveParameterValue('{"login":"john","password":"zer0day"}'),
    );

    expect($password->toArray())->toBe([
        'credentials' => [
            'login' => 'john',
            'password' => 'zer0day',
        ],
    ]);
});

it('creates instance from static method', function () {
    $password = Password::fromLoginAndPassword('john', 'jop');

    expect($password->credentials())->toBe([
        'login' => 'john',
        'password' => 'jop',
    ]);
});
