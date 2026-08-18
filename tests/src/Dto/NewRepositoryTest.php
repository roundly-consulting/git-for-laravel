<?php

declare(strict_types=1);

use RoundlyConsulting\Git\Dto\Input\NewRepository;

it('keeps the existing empty-name guard', function () {
    new NewRepository('   ');
})->throws(InvalidArgumentException::class, 'must not be empty');

it('defaults every provisioning field to today\'s behaviour', function () {
    $data = new NewRepository('widget');

    expect($data)->owner->toBeNull()->template->toBeNull()->autoInit->toBeFalse()->defaultBranch->toBeNull();
});

it('accepts the fields positionally after the original three', function () {
    // The backwards-compatibility claim, asserted rather than assumed: a caller written
    // against the three-argument version must still bind to the same parameters.
    $data = new NewRepository('widget', true, 'd', 'acme', 'roundly-consulting/tpl', true, 'develop');

    expect($data)->name->toBe('widget')->private->toBeTrue()->description->toBe('d')
        ->owner->toBe('acme')->template->toBe('roundly-consulting/tpl')
        ->autoInit->toBeTrue()->defaultBranch->toBe('develop');
});

it('rejects a malformed template selector', function (string $template) {
    new NewRepository(name: 'widget', template: $template);
})->with([
    'no owner' => 'package-template',
    'trailing slash' => 'roundly-consulting/',
    'leading slash' => '/package-template',
    'a path, not a selector' => 'roundly-consulting/package-template/main',
    'blank' => ' / ',
])->throws(InvalidArgumentException::class, 'owner/repo');

it('rejects a default branch on a repository that would have no commit', function () {
    new NewRepository(name: 'widget', defaultBranch: 'develop');
})->throws(InvalidArgumentException::class, 'requires an initial commit');

it('allows a default branch once there is a commit to put it on', function () {
    expect(new NewRepository(name: 'widget', autoInit: true, defaultBranch: 'develop'))
        ->defaultBranch->toBe('develop')
        ->and(new NewRepository(name: 'widget', template: 'a/b', defaultBranch: 'develop'))
        ->defaultBranch->toBe('develop');
});
