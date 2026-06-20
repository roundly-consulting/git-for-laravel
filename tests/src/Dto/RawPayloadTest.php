<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use RoundlyConsulting\Git\Dto\Author;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Enums\ProviderName;

it('exposes the raw payload but excludes it from serialization', function () {
    $raw = ['sha' => 'abc', 'extra' => 'kept'];

    $commit = new Commit(
        provider: ProviderName::Github,
        sha: 'abc',
        message: 'msg',
        author: new Author('n', 'e', null),
        url: null,
        commitAt: Carbon::parse('2020-01-01T00:00:00Z'),
        raw: $raw,
    );

    expect($commit->raw())->toBe($raw)
        ->and($commit->toArray())->not->toHaveKey('raw')
        ->and($commit->toJson())->not->toContain('"raw"')
        ->and($commit->toJson())->not->toContain('kept');
});
