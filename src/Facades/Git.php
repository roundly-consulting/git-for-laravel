<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Facades;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Credentials\GithubApp;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\GitManager;
use RoundlyConsulting\Git\Interfaces\Provider;
use RoundlyConsulting\Git\Providers\Bitbucket;
use RoundlyConsulting\Git\Providers\Github;
use RoundlyConsulting\Git\Providers\Gitlab;
use RoundlyConsulting\Git\Testing\GitFake;
use RoundlyConsulting\Git\Testing\ProviderFake;
use RoundlyConsulting\Git\Testing\RecordedCall;

/**
 * @method static Provider|Github github(?Credentials $credentials = null)
 * @method static Provider|Github githubApp(?GithubApp $credentials = null)
 * @method static Provider|Gitlab gitlab(?Credentials $credentials = null)
 * @method static Provider|Bitbucket bitbucket(?Credentials $credentials = null)
 * @method static Provider provider(ProviderName|string $provider, ?Credentials $credentials = null)
 * @method static array<string, bool> capabilities(ProviderName|string $provider)
 * @method static Credentials|null credentials(ProviderName|string $provider)
 * @method static bool verifyWebhook(ProviderName|string $provider, Request $request)
 * @method static ProviderFake fakeFor(ProviderName $name)
 * @method static list<RecordedCall> recorded(ProviderName $provider, ?string $method = null)
 * @method static void assertSent(ProviderName $provider, string $method, ?Closure $callback = null)
 * @method static void assertSentTimes(ProviderName $provider, string $method, int $times)
 * @method static void assertNotSent(ProviderName $provider, string $method, ?Closure $callback = null)
 * @method static void assertNothingSent(?ProviderName $provider = null)
 * @method static void assertBatched(ProviderName $provider, string $method)
 * @method static void assertNotBatched(ProviderName $provider, string $method)
 * @method static void assertRepositoryCreated(string $name, ?string $owner = null, ?string $template = null)
 * @method static void assertNoRepositoryCreated()
 *
 * @see GitManager
 * @see GitFake
 */
final class Git extends Facade
{
    /**
     * Swap in the recording fake: no HTTP leaves the process, every driver is a seedable
     * `ProviderFake`, and an injected `GitManager` receives the fake too.
     */
    public static function fake(): GitFake
    {
        $fake = app(GitFake::class);

        self::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return GitManager::class;
    }
}
