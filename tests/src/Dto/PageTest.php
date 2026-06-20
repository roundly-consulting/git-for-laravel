<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use RoundlyConsulting\Git\Dto\Page;

it('wraps a list of items with pagination metadata', function () {
    $page = new Page(items: ['a', 'b'], perPage: 2, page: 1, hasMore: true, nextCursor: 'c');

    expect($page->count())->toBe(2)
        ->and($page->first())->toBe('a')
        ->and($page->isEmpty())->toBeFalse()
        ->and($page->hasMore)->toBeTrue()
        ->and($page->collect())->toBeInstanceOf(Collection::class)
        ->and(iterator_to_array($page->getIterator()))->toBe(['a', 'b']);
});

it('reports an empty page', function () {
    $page = new Page(items: [], perPage: 10, page: 1, hasMore: false);

    expect($page->isEmpty())->toBeTrue()
        ->and($page->first())->toBeNull()
        ->and($page->count())->toBe(0);
});
