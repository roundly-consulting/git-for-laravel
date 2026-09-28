<?php

declare(strict_types=1);

use Illuminate\Cache\ArrayLock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Git\Auth\TokenManager;
use RoundlyConsulting\Git\Dto\Credentials\OauthToken;
use RoundlyConsulting\Git\Events\OauthTokenRefreshed;

/*
 * Two workers with an expired token both reach the refresh. With a provider that ROTATES
 * refresh tokens (GitLab), the second POST presents a token the first already spent and
 * gets `invalid_grant`. The refresh therefore runs under a cache lock, and whoever gets
 * the lock second re-reads the cache before refreshing.
 *
 * The interleaving is simulated, not raced: a cache store whose lock runs a hook at the
 * moment a waiter would acquire it — "worker A finished while B was waiting".
 */

/**
 * An array store whose locks record that they were taken and run `$onAcquire` first.
 */
function interleavingStore(Closure $onAcquire, array &$acquired): void
{
    $store = new class($onAcquire, $acquired) extends ArrayStore
    {
        /** @param  array<int, string>  $acquired */
        public function __construct(private Closure $onAcquire, private array &$acquired)
        {
            parent::__construct();
        }

        public function lock($name, $seconds = 0, $owner = null): Lock
        {
            return new class($this, $name, $seconds, $owner, $this->onAcquire, $this->acquired) extends ArrayLock
            {
                /** @param  array<int, string>  $acquired */
                public function __construct($store, $name, $seconds, $owner, private Closure $onAcquire, private array &$acquired)
                {
                    parent::__construct($store, $name, $seconds, $owner);
                }

                public function block($seconds, $callback = null)
                {
                    $this->acquired[] = $this->name;
                    ($this->onAcquire)();

                    return parent::block($seconds, $callback);
                }
            };
        }
    };

    Cache::extend('interleaving', fn () => Cache::repository($store));
    config()->set('cache.stores.interleaving', ['driver' => 'interleaving']);
    config()->set('git.cache.store', 'interleaving');
}

it('uses the token another worker refreshed while this one waited for the lock', function (): void {
    Event::fake([OauthTokenRefreshed::class]);
    Http::fake(['https://token.test' => Http::response(['access_token' => 'from-worker-b', 'refresh_token' => 'spent', 'expires_in' => 3600])]);

    $acquired = [];
    interleavingStore(function (): void {
        // Worker A's refresh landed: the entry under the OLD refresh token now holds the
        // fresh access token and the rotated refresh token.
        Cache::store('interleaving')->put('git:oauth:'.hash('sha256', 'old-refresh'), [
            'token' => 'from-worker-a',
            'expires_at' => time() + 3000,
            'refresh_token' => 'rotated-by-a',
        ], 3000);
    }, $acquired);

    $cred = OauthToken::for('expired', 'old-refresh', 'client', 'secret', 'https://token.test', Carbon::now()->subMinute());

    expect(app(TokenManager::class)->oauthToken($cred))->toBe('from-worker-a')
        ->and($acquired)->toHaveCount(1);

    Http::assertNothingSent();
    Event::assertNotDispatched(OauthTokenRefreshed::class);
});

it('refreshes under the lock when nobody else did', function (): void {
    Http::fake(['https://token.test' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600])]);

    $acquired = [];
    interleavingStore(fn () => null, $acquired);

    $cred = OauthToken::for('expired', 'old-refresh', 'client', 'secret', 'https://token.test', Carbon::now()->subMinute());

    expect(app(TokenManager::class)->oauthToken($cred))->toBe('fresh')
        ->and($acquired)->toBe(['git:oauth:'.hash('sha256', 'old-refresh').':refresh']);

    Http::assertSentCount(1);
});

it('takes no lock while the token is still fresh', function (): void {
    Http::fake();

    $acquired = [];
    interleavingStore(fn () => null, $acquired);

    $cred = OauthToken::for('valid', 'old-refresh', 'client', 'secret', 'https://token.test', Carbon::now()->addHour());

    expect(app(TokenManager::class)->oauthToken($cred))->toBe('valid')
        ->and($acquired)->toBe([]);
});

it('refreshes anyway when the lock holder is stuck past the wait', function (): void {
    Http::fake(['https://token.test' => Http::response(['access_token' => 'fresh', 'expires_in' => 3600])]);

    $acquired = [];
    interleavingStore(fn () => throw new LockTimeoutException, $acquired);

    $cred = OauthToken::for('expired', 'old-refresh', 'client', 'secret', 'https://token.test', Carbon::now()->subMinute());

    expect(app(TokenManager::class)->oauthToken($cred))->toBe('fresh');

    Http::assertSentCount(1);
});
