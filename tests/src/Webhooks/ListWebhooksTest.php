<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Facades\Registry;

it('lists gitlab webhooks', function () {
    Http::fake(['*/projects/*/hooks' => Http::response([
        ['id' => 3, 'url' => 'https://app.test/hook', 'push_events' => true, 'enable_ssl_verification' => true],
    ])]);

    $hooks = Registry::gitlab(Token::from('x'))->listWebhooks('acme/web');

    expect($hooks)->toHaveCount(1)
        ->and($hooks[0]->id)->toBe('3')
        ->and($hooks[0]->events)->toBe(['push']);
});

it('lists bitbucket webhooks', function () {
    Http::fake(['*/repositories/*/hooks' => Http::response([
        'values' => [
            ['uuid' => '{1}', 'url' => 'https://app.test/hook', 'events' => ['repo:push'], 'active' => true],
        ],
    ])]);

    $hooks = Registry::bitbucket(Token::from('x'))->listWebhooks('acme/api');

    expect($hooks)->toHaveCount(1)
        ->and($hooks[0]->id)->toBe('{1}');
});
