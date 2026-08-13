<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Enums\ProviderName;
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

it('reads an installation dto', function () {
    $installation = new Installation(
        provider: ProviderName::Github,
        id: '51234567',
        accountLogin: 'acme-inc',
        accountType: 'Organization',
        repositorySelection: 'all',
        permissions: ['contents' => 'write'],
        suspendedAt: Carbon::parse('2026-08-01T10:00:00Z'),
    );

    expect($installation->reachesEveryRepository())->toBeTrue()
        ->and($installation->isSuspended())->toBeTrue()
        ->and((new Installation(ProviderName::Github, '1', 'a', 'User', 'selected'))->reachesEveryRepository())->toBeFalse()
        ->and((new Installation(ProviderName::Github, '1', 'a', 'User', 'selected'))->isSuspended())->toBeFalse();
});
