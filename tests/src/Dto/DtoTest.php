<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Tests\testable\BaseDto;

it('converts dto to array', function () {
    $object = new stdClass;
    $object->name = 'John';

    $dto = new BaseDto([
        'string' => 'my-value',
        'dto' => new BaseDto('is also ok'),
        'object' => $object,
    ]);

    expect($dto)->toArray()->toBe([
        'value' => [
            'string' => 'my-value',
            'dto' => [
                'value' => 'is also ok',
            ],
            'object' => [
                'name' => 'John',
            ],
        ],
    ]);
});

it('returns array of data to be json serialized', function () {
    $dto = new BaseDto('my-value');

    expect($dto)->jsonSerialize()->toBe(['value' => 'my-value']);
});

it('converts dto to json', function () {
    $dto = new BaseDto('my-value');

    expect($dto)->toJson()->toBe('{"value":"my-value"}');
});
