<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/git-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=git-for-laravel">
    <img src="art/hero.png" alt="Git for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

# Git for Laravel

Access git repositories on **GitHub**, **GitLab**, and **Bitbucket** through a single,
Laravel-native API. Authenticate once, then page through repositories and commits, read pull
requests, issues, tags, releases, file contents, diffs, contributors, and languages, perform
write operations (create repos, branches, files, pull requests, comments, releases, tags,
webhooks), receive signed webhooks as typed events, and build authenticated clone URLs — all
returning typed data objects, all built on `Illuminate\Http\Client` with no third-party
runtime dependencies.

## Requirements

- PHP 8.4+
- Laravel 12 or 13

## Installation

```bash
composer require roundly-consulting/git-for-laravel
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="git-config"
```

If you enable webhook receiving you can also publish the route stub:

```bash
php artisan vendor:publish --tag="git-routes"
```

## Configuration

The published `config/git.php` holds one block per provider plus shared blocks for caching,
logging, and webhooks. Every value is backed by an environment variable, and the package
works with zero configuration.

```php
return [
    'providers' => [
        'github' => [
            'url' => env('GITHUB_API_URL', 'https://api.github.com'),
            'token' => env('GITHUB_TOKEN'),
            'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
            'timeout' => env('GITHUB_TIMEOUT', 10),
            'retry' => [
                'times' => env('GITHUB_RETRY_TIMES', 1),
                'backoff' => env('GITHUB_RETRY_BACKOFF', 0),
            ],
            'rateLimits' => [
                'enabled' => env('GITHUB_RATELIMIT_ENABLED', true),
                'owner' => env('GITHUB_RATELIMIT_OWNER', 'app'),
                'maxAttempts' => env('GITHUB_RATELIMIT', 5000),
                'timespan' => env('GITHUB_RATELIMIT_TIMESPAN', 'hour'),
                'adaptive' => env('GITHUB_RATELIMIT_ADAPTIVE', true),
                'max_wait' => env('GITHUB_RATELIMIT_MAX_WAIT'), // ms; null = wait/pace
                'jitter' => env('GITHUB_RATELIMIT_JITTER'),      // ms; null = none
            ],
            'options' => ['headers' => [/* User-Agent, X-GitHub-Api-Version */]],

            // GitHub App authentication (self-refreshing installation tokens).
            'app' => [
                'id' => env('GITHUB_APP_ID'),
                'installation_id' => env('GITHUB_APP_INSTALLATION_ID'),
                'private_key' => env('GITHUB_APP_PRIVATE_KEY'), // PEM string or file path
                'slug' => env('GITHUB_APP_SLUG'),               // builds the install URL
                'permissions' => [                               // default scope for a per-operation mint
                    'contents' => 'write',
                    'pull_requests' => 'write',
                    'metadata' => 'read',
                ],
            ],
            // OAuth credentials (self-refreshing access tokens).
            'oauth' => [
                'client_id' => env('GITHUB_OAUTH_CLIENT_ID'),
                'client_secret' => env('GITHUB_OAUTH_CLIENT_SECRET'),
                'token_url' => env('GITHUB_OAUTH_TOKEN_URL', 'https://github.com/login/oauth/access_token'),
            ],
        ],
        // gitlab and bitbucket follow the same shape (gitlab also ships an `oauth` block).
    ],

    'cache'   => ['enabled' => env('GIT_CACHE_ENABLED', false), 'store' => env('GIT_CACHE_STORE'), 'ttl' => env('GIT_CACHE_TTL', 3600)],
    'logging' => ['enabled' => env('GIT_LOGGING_ENABLED', false), 'channel' => env('GIT_LOGGING_CHANNEL')],
    'webhooks'=> ['enabled' => env('GIT_WEBHOOKS_ENABLED', false), 'path' => env('GIT_WEBHOOKS_PATH', 'git/webhooks'), 'middleware' => ['api']],
    'batch'   => ['concurrency' => env('GIT_BATCH_CONCURRENCY', 25)],
];
```

| Key | Type | Default | Purpose |
|---|---|---|---|
| `providers.<name>.url` | string | provider API base URL | Base URL for the provider's API. |
| `providers.<name>.token` | string\|null | `null` | Default access token (`Registry::github()` uses it). |
| `providers.<name>.webhook_secret` | string\|null | `null` | Secret used to verify incoming webhooks. |
| `providers.<name>.timeout` | int | `10` | HTTP request timeout in seconds. |
| `providers.<name>.retry` | array\|int | `{times:1, backoff:0}` | Retry attempts and backoff (ms) for 429/5xx. |
| `providers.<name>.rateLimits.enabled` | bool | `true` | Client-side throttling on/off; `false` sends with no limiter. |
| `providers.<name>.rateLimits.owner` | string | `app` | Client-side throttle bucket key (`git:<provider>:<owner>`). |
| `providers.<name>.rateLimits.maxAttempts` | int | provider quota | Max requests per timespan. |
| `providers.<name>.rateLimits.timespan` | string | provider window | `second`, `minute`, `hour`, or `day`. |
| `providers.<name>.rateLimits.adaptive` | bool | `true` | Honour the provider's own `Retry-After` / `X-RateLimit-*` headers. |
| `providers.<name>.rateLimits.max_wait` | int\|null | `null` | Max defer in ms before failing fast; `null` waits/paces instead. |
| `providers.<name>.rateLimits.jitter` | int\|null | `null` | Random jitter in ms added to each defer. |
| `cache.enabled` | bool | `false` | Store ETags and serve `304 Not Modified` from cache. |
| `cache.store` | string\|null | default store | Cache store used for conditional requests. |
| `cache.ttl` | int | `3600` | Cached-response TTL in seconds. |
| `logging.enabled` | bool | `false` | Log method/URL/status/duration (never tokens or bodies). |
| `logging.channel` | string\|null | default channel | Log channel for request logging. |
| `webhooks.enabled` | bool | `false` | Register the webhook receiving route. |
| `webhooks.path` | string | `git/webhooks` | Base path for `POST {path}/{provider}`. |
| `webhooks.middleware` | array | `['api']` | Middleware applied to the webhook route. |
| `batch.concurrency` | int | `25` | Max concurrent requests per pool; larger inputs are chunked. |
| `providers.github.app.id` | string\|null | `null` | GitHub App id; when set, `Registry::github()` mints installation tokens. |
| `providers.github.app.installation_id` | string\|null | `null` | GitHub App installation id. |
| `providers.github.app.private_key` | string\|null | `null` | GitHub App private key — a PEM string or a file path. |
| `providers.<name>.oauth.client_id` | string\|null | `null` | OAuth client id, read by `OauthToken::forProvider()`. |
| `providers.<name>.oauth.client_secret` | string\|null | `null` | OAuth client secret, read by `OauthToken::forProvider()`. |
| `providers.<name>.oauth.token_url` | string | provider token URL | OAuth token endpoint used to refresh access tokens. |

Environment variables: `GITHUB_TOKEN`, `GITLAB_TOKEN`, `BITBUCKET_TOKEN`,
`*_WEBHOOK_SECRET`, `GIT_CACHE_ENABLED`, `GIT_LOGGING_ENABLED`, `GIT_WEBHOOKS_ENABLED`,
`GIT_WEBHOOKS_PATH`, `GIT_BATCH_CONCURRENCY`, `GITHUB_APP_ID`, `GITHUB_APP_INSTALLATION_ID`,
`GITHUB_APP_PRIVATE_KEY`, `GITHUB_APP_SLUG`, `GITHUB_OAUTH_CLIENT_ID`, `GITHUB_OAUTH_CLIENT_SECRET`,
`GITHUB_OAUTH_TOKEN_URL` (plus the GitLab equivalents), and the per-provider
`*_RETRY_TIMES` / `*_RETRY_BACKOFF` / timeout / rate-limit keys.

> App and OAuth tokens are cached so they survive across requests; point `cache.store` at a
> shared store (Redis, database, file) rather than the `array` driver when you use them.

Rate limiting is enforced client-side by [`http-client-rate-limits-for-laravel`](https://github.com/roundly-consulting/http-client-rate-limits-for-laravel).
By default requests are **paced** — when a provider's window is exhausted the call waits until
the window frees up rather than failing. Set `max_wait` (ms) to fail fast instead: a defer that
would exceed it throws git's typed `RateLimitExceededException`. With `adaptive` on (the default)
the limiter also reads each provider's own `Retry-After` / `X-RateLimit-*` response headers and
backs off exactly as the server asks — complementing the server-status snapshot exposed by
`$provider->rateLimit()`.

That exception carries the wait as a retry hint via the toolkit's `HasRetryAfter` contract, so a
host can translate any throttled roundly package into a `Retry-After` header the same way:

```php
use RoundlyConsulting\PackageToolkit\Contracts\HasRetryAfter;

if ($e instanceof HasRetryAfter) {
    return response('Too Many Requests', 429, ['Retry-After' => $e->retryAfterSeconds()]);
}
```

The limiter defaults to an in-memory store (per process — fine for CLI and single-worker use).
For a quota **shared across workers or servers**, register the provider package and point its
`http-client-rate-limits.store` at a `CacheStore`, `RedisStore`, or `DatabaseStore`
(`HTTP_CLIENT_RATE_LIMITS_STORE`); git does not force a store.

## Integrates with

git-for-laravel builds on four other roundly-consulting packages, each a hard dependency wired by
path locally and VCS on CI until they publish to Packagist:

- **[package-toolkit-for-laravel](https://github.com/roundly-consulting/package-toolkit-for-laravel)**
  — the service provider is built on the toolkit's package builder, so the config/routes/commands
  wiring and the `git-config` / `git-routes` publish tags are declared once. It also contributes a
  `php artisan about` section and backs the `Retry-After` hint on `RateLimitExceededException`.
- **[crypto-for-laravel](https://github.com/roundly-consulting/crypto-for-laravel)** — owns every
  cryptographic primitive git uses: the HMAC-SHA256 + constant-time compare behind webhook
  signature verification, and the RS256 JWS that authenticates a GitHub App. git re-implements no
  crypto of its own (an architecture test enforces it) and reads its key material from its own
  config — crypto itself is zero-config.
- **[enums-for-laravel](https://github.com/roundly-consulting/enums-for-laravel)** — powers the
  `Feature`, `ProviderName`, and `ResourceState` enums with `values()`, `labels()`, `options()`,
  `validationRule()`, and case lookups.
- **[http-client-rate-limits-for-laravel](https://github.com/roundly-consulting/http-client-rate-limits-for-laravel)**
  — the client-side outbound rate limiter (pacing, adaptive `Retry-After` back-off, jitter,
  compound windows, and shared Cache/Redis/DB stores) behind every provider request.

## Usage

Resolve a provider through the `Registry` facade. With a token in config you don't pass a
credential at all; an explicit `Token` always overrides config.

```php
use RoundlyConsulting\Git\Facades\Registry;
use RoundlyConsulting\Git\Dto\Credentials\Token;

$github = Registry::github();                       // uses config('git.providers.github.token')
$github = Registry::github(Token::from('ghp_...')); // explicit override
$gitlab = Registry::provider('gitlab');             // by string, class-string, or ProviderName enum
```

### The authenticated user

```php
$owner = $github->user();

$owner->id;     // "1"
$owner->name;   // "octocat"
$owner->avatar; // "https://github.com/images/error/octocat_happy.gif"
```

### Repositories (paginated)

List endpoints return a `Page` and never silently truncate. Use `allRepositories()` for a
lazily auto-paginating `LazyCollection`.

```php
$page = $github->repositories(perPage: 50); // Page<Repository>
$page->items;     // list<Repository>
$page->hasMore;   // bool
$page->collect(); // Collection<Repository>

foreach ($github->allRepositories() as $repository) {
    // streams across all pages, one request at a time
}

$repository = $github->repository('octocat/Hello-World');
$repository->name;          // "Hello-World"
$repository->defaultBranch; // "master"
```

### Branches and commits (fluent query)

```php
$branches = $github->branches('octocat/Hello-World')->items; // ['main', 'dev']

$commits = $github->commits('octocat/Hello-World')   // CommitQuery
    ->branch('feature/new ui')                       // properly URL-encoded
    ->author('octocat')
    ->path('src/')
    ->since(now()->subWeek())
    ->until(now())
    ->perPage(50)
    ->get();                                         // Page<Commit>

foreach ($github->commits('octocat/Hello-World')->branch('main')->lazy() as $commit) {
    // auto-paginated stream
}

$commit = $github->commit('octocat/Hello-World', '6dcb09b...');
```

### More read endpoints

```php
$github->pullRequests('octocat/Hello-World');     // Page<PullRequest>  (GitLab MRs, Bitbucket PRs)
$github->pullRequest('octocat/Hello-World', 7);
$github->issues('octocat/Hello-World');           // Page<Issue>
$github->tags('octocat/Hello-World');             // Page<Tag>
$github->releases('octocat/Hello-World');         // Page<Release>
$github->release('octocat/Hello-World', 'v1.0');
$github->contents('octocat/Hello-World', 'README.md', ref: 'main'); // FileContent (decoded)
$github->compare('octocat/Hello-World', 'main', 'feature');         // Comparison
$github->contributors('octocat/Hello-World');     // Page<Contributor>
$github->languages('octocat/Hello-World');        // ['PHP' => 12345, ...]
$github->searchRepositories('laravel');           // Page<Repository>
```

Capabilities differ per provider; calling one a provider doesn't support throws a
`FeatureNotSupportedException` — see **Feature detection** to branch on support instead.

### Canonical data model and the raw escape hatch

Every resource DTO is provider-agnostic: it carries the `ProviderName` it came from, a
normalized `ResourceState` enum (instead of a raw `'open'`/`'opened'`/`'OPEN'` string), and a
`raw()` accessor returning the exact decoded provider payload for any field the DTO doesn't
model. `raw` is never serialized into `toArray()`/`toJson()`.

```php
use RoundlyConsulting\Git\Enums\ResourceState;

$pr = $github->pullRequest('octocat/Hello-World', 1);

$pr->state;                 // ResourceState::Open — identical across GitHub/GitLab/Bitbucket
$pr->raw()['mergeable'];    // any unmodelled provider field, still reachable
```

### Concurrent fetches with batch()

`batch()` fans a list of reads out over a single `Http::pool()` round trip, returning a keyed
`BatchResult` that separates successes from per-key failures (no exception unless you ask for
one). Pooled requests carry the same authentication as single requests but bypass the ETag
conditional cache.

```php
$result = $github->batch()->languages(['acme/api', 'acme/web']); // BatchResult<array<string,int>>

$result->results();          // ['acme/api' => ['PHP' => 80, ...], ...]
$result->get('acme/api');
foreach ($result->errors() as $key => $error) {
    report("{$key} failed with {$error->status}");
}

$result->throwOnError();     // raises BatchRequestException if any key failed

$github->batch()->repositories(['acme/api', 'acme/web']);   // BatchResult<Repository>
$github->batch()->contents('acme/api', ['README.md', 'composer.json']);
$github->batch()->pullRequest(['main' => 'acme/api#7']);    // id => PullRequest, ref "path#number"
```

### Write operations

All writes take typed input DTOs and require an authenticated provider.

```php
use RoundlyConsulting\Git\Dto\Input\{NewRepository, NewBranch, NewFile, NewPullRequest, NewComment, NewRelease, NewTag, NewWebhook};

$repo = $github->createRepository(new NewRepository(name: 'acme', private: true));
$github->createBranch('acme/acme', new NewBranch('feature/ci', 'main'));
$github->createFile('acme/acme', new NewFile('ci.yml', '...', 'Add CI', 'feature/ci'));
$pr = $github->createPullRequest('acme/acme', new NewPullRequest('Add CI', 'feature/ci', 'main'));
$github->comment('acme/acme', new NewComment(number: $pr->number, body: 'LGTM'));
$github->createRelease('acme/acme', new NewRelease('v1.0', 'First release'));
$webhook = $github->createWebhook('acme/acme', new NewWebhook('https://example.com/hook', ['push'], secret: '...'));
$github->deleteWebhook('acme/acme', $webhook->id);
```

### Resilience, caching, and rate limits

Requests retry idempotent 429/5xx responses with backoff and are paced by the client-side rate
limiter (see [Configuration](#configuration)) — waiting when a window is exhausted, failing fast
with `RateLimitExceededException` only when a `max_wait` is set, and adapting to the provider's
own `Retry-After` headers. With `cache.enabled`, ETags are stored and conditional requests serve
`304` responses from cache. The last response's server-reported rate limit is exposed:

```php
$status = $github->rateLimit(); // ?RateLimitStatus { limit, remaining, used, resetAt }
```

### Clone URL

```php
$url = $github->cloneUrlForRepository('octocat/Hello-World', 'octocat', Token::from('ghp_...'));
// https://token:ghp_...@github.com/octocat/Hello-World.git
```

A refreshable credential is asked for a live token instead of being read for a static one, and
an installation token gets GitHub's documented username:

```php
$url = $github->cloneUrlForRepository('octocat/Hello-World', 'octocat', $installationCredentials);
// https://x-access-token:ghs_...@github.com/octocat/Hello-World.git
```

Two consequences worth knowing: for an installation credential this is a **network call**
(it mints, or reads a cached token), and a `GithubApp` credential is **refused** — the app's
own JWT can mint a token for every installation of the app, and a clone URL ends up in
`git remote` and in process environments.

### Self-refreshing GitHub App / OAuth credentials

Static `Token` credentials never expire, but GitHub App installation tokens and OAuth access
tokens do. `GithubAppToken` and `OauthToken` mint, cache, and refresh the access token
transparently — reads, writes, and batches all benefit, with secrets redacted from any
serialization. The App JWT is a real RS256 JWS, signed through
[crypto-for-laravel](https://github.com/roundly-consulting/crypto-for-laravel) — no third-party
JWT library.

```php
use RoundlyConsulting\Git\Dto\Credentials\{GithubAppToken, OauthToken};
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Enums\ProviderName;

$github = Registry::github(GithubAppToken::for(
    appId: config('git.providers.github.app.id'),
    installationId: config('git.providers.github.app.installation_id'),
    privateKey: config('git.providers.github.app.private_key'), // PEM string or file path
));
$github->repositories(); // installation token minted, cached to expiry, reused

// The OAuth client (`client_id`, `client_secret`, `token_url`) is static per provider, so
// `forProvider()` takes it from `git.providers.github.oauth.*` and asks only for the
// per-user half. Pass all five to `OauthToken::for()` to bypass config entirely.
$github = Registry::github(OauthToken::forProvider(
    ProviderName::Github,
    accessToken: $access, refreshToken: $refresh, expiresAt: $expiresAt,
));
```

When `git.providers.github.app.id` is configured, `Registry::github()` builds a
`GithubAppToken` automatically — no explicit credential needed. Use a shared cache store (not
the `array` driver) so minted tokens persist across requests.

#### Repository-scoped tokens

An installation token with no scope reaches **every** repository the app is installed on, for
an hour. Narrow it per operation instead — this is the point of minting rather than storing a
credential:

```php
use RoundlyConsulting\Git\Dto\Input\InstallationTokenScope;

$scoped = GithubAppToken::for(appId: $id, installationId: $installation, privateKey: $key)
    ->forScope(InstallationTokenScope::forRepositories(repositoryIds: ['40823311']));

Registry::github($scoped)->cloneUrlForRepository('acme/api', 'acme', $scoped);
```

`forRepositories()` takes the permission set from `git.providers.github.app.permissions`.
Either selector works — `repositoryIds` (numeric, survives a rename) or `repositories`
(names) — and they are equally narrow.

An **empty** scope is refused outright: a scope object that names no repository would mint a
token for every repository in the installation, so `TokenManager` throws rather than
silently widening. Passing **no scope at all** (`scope === null`) is still the deliberate
connection-wide path, used for listing an installation's repositories. The token cache is keyed
per scope, so a scoped mint can never be served a wider cached token. A scope the installation
cannot satisfy (unknown repository, ungranted permission) and a vanished installation raise
`InvalidCredentialsException`; a `403` (rate limit, suspension) stays a `RequestException`,
because consumers treat the former as "reconnect required".

#### Acting as the app, and installing it

`/app/**` endpoints authenticate with the app's own JWT rather than an installation token.
That is how you verify an installation id before trusting it — for instance one that arrived
from a browser redirect:

`Registry::githubApp()` reads `git.providers.github.app.{id,private_key}` and throws
`InvalidCredentialsException` naming the missing key when either is absent. The `/app/**`
endpoints require **app** credentials and the installation endpoints require an
**installation** credential; using the wrong one raises this package's own exception rather
than GitHub's opaque 403.

```php
$installation = Registry::githubApp()->installation($installationIdFromTheRedirect);

$installation->accountLogin;            // "acme-inc"
$installation->repositorySelection;     // "all" | "selected"
$installation->reachesEveryRepository();
$installation->isSuspended();

Registry::github($credentials)->installationRepositories(); // NOT /user/repos: an
                                                            // installation token 403s there
```

Send a human to install the app with `installUrl()`; GitHub echoes `state` back to the app's
Setup URL alongside `installation_id`, which is what ties the redirect that returns to the
request that left:

```php
$url = Registry::githubApp()->installUrl($state); // https://github.com/apps/<slug>/installations/new?state=…
```

### Webhooks

Set `GIT_WEBHOOKS_ENABLED=true` and a `*_WEBHOOK_SECRET` per provider. Incoming requests to
`POST {webhooks.path}/{provider}` are signature-verified (GitHub `X-Hub-Signature-256`, GitLab
`X-Gitlab-Token`, Bitbucket `X-Hub-Signature`) and dispatched as events:

Inbound payloads run through the same canonical mappers, so listeners get typed, provider-
agnostic accessors instead of hand-parsing three raw shapes (`raw()` stays available):

```php
use RoundlyConsulting\Git\Events\{WebhookReceived, PushReceived, PullRequestEventReceived};

Event::listen(PushReceived::class, function (PushReceived $event) {
    foreach ($event->commits() as $commit) {   // list<Commit>, canonical
        $commit->sha;
    }
    $event->ref();          // 'refs/heads/main'
    $event->repository();    // ?Repository
    $event->pusher();        // ?Author
});

Event::listen(PullRequestEventReceived::class, function (PullRequestEventReceived $event) {
    $event->pullRequest()?->state; // ResourceState
    $event->action();              // raw event type
});
```

A missing or invalid signature returns `403` and dispatches nothing. The HMAC is computed over the
**raw** request body and compared in constant time (via crypto-for-laravel), so a forged payload
never reaches your listeners and a partially-correct signature leaks nothing through timing.

### Webhook auto-registration

`webhooks($repo)` ties this app's inbound route to the provider's outbound create-webhook op:
it derives the URL from the published `git.webhooks` route, defaults the secret to the
configured `webhook_secret`, and is idempotent (a hook with the same URL is never created
twice).

```php
$github->webhooks('acme/api')->register();              // returns the existing or new Webhook
$github->webhooks('acme/api')->register(events: ['push', 'pull_request']);
$github->webhooks('acme/api')->all();                   // list<Webhook>
$github->webhooks('acme/api')->registered($url);        // bool
$github->webhooks('acme/api')->deleteByUrl($url);
```

### Artisan commands

```bash
php artisan git:repos github [--json]
php artisan git:rate-limit github
php artisan git:commits github octocat/Hello-World --branch=main --since=2024-01-01
php artisan git:webhook github acme/api [--url=] [--events=push] [--secret=] [--list] [--delete=ID]
```

### Testing without real HTTP

```php
use RoundlyConsulting\Git\Facades\Registry;
use RoundlyConsulting\Git\Enums\ProviderName;

$fake = Registry::fake();
$fake->github()->seedRepositories([$repositoryDto]);

// run code that calls Registry::github()->repositories() ...

$fake->assertSent(ProviderName::Github, 'repositories');
$fake->assertRepositoryCreated('acme/new-repo');
```

### Feature detection

Branch on the capability matrix instead of catching `FeatureNotSupportedException`:

```php
use RoundlyConsulting\Git\Enums\Feature;

if ($github->supports(Feature::ListCommits)) {
    $github->commits('octocat/Hello-World')->get();
}

$github->supportsAll(Feature::CreateRelease, Feature::CreateTag); // bool
$github->supportsAny(Feature::Languages);                         // bool
$github->capabilities();                                          // ['repositories' => true, ...]
$github->featureMatrix();                                         // list<FeatureInfo> with ->supported
Registry::capabilities(ProviderName::Bitbucket);                  // without authenticating
```

The testing fake also records pooled calls — `$fake->assertBatched(ProviderName::Github, 'languages')`.

### Extending the registry

`Registry` is macroable, so you can register your own provider shortcuts:

```php
Registry::macro('myHost', fn ($credentials = null) => $this->provider(MyProvider::class, $credentials));
```

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
