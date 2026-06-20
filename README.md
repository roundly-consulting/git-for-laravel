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
                'owner' => env('GITHUB_RATELIMIT_OWNER', 'app'),
                'maxAttempts' => env('GITHUB_RATELIMIT', 5000),
                'timespan' => env('GITHUB_RATELIMIT_TIMESPAN', 'hour'),
            ],
            'options' => ['headers' => [/* User-Agent, X-GitHub-Api-Version */]],
        ],
        // gitlab and bitbucket follow the same shape.
    ],

    'cache'   => ['enabled' => env('GIT_CACHE_ENABLED', false), 'store' => env('GIT_CACHE_STORE'), 'ttl' => env('GIT_CACHE_TTL', 3600)],
    'logging' => ['enabled' => env('GIT_LOGGING_ENABLED', false), 'channel' => env('GIT_LOGGING_CHANNEL')],
    'webhooks'=> ['enabled' => env('GIT_WEBHOOKS_ENABLED', false), 'path' => env('GIT_WEBHOOKS_PATH', 'git/webhooks'), 'middleware' => ['api']],
];
```

| Key | Type | Default | Purpose |
|---|---|---|---|
| `providers.<name>.url` | string | provider API base URL | Base URL for the provider's API. |
| `providers.<name>.token` | string\|null | `null` | Default access token (`Registry::github()` uses it). |
| `providers.<name>.webhook_secret` | string\|null | `null` | Secret used to verify incoming webhooks. |
| `providers.<name>.timeout` | int | `10` | HTTP request timeout in seconds. |
| `providers.<name>.retry` | array\|int | `{times:1, backoff:0}` | Retry attempts and backoff (ms) for 429/5xx. |
| `providers.<name>.rateLimits.owner` | string | `app` | Client-side throttle bucket key. |
| `providers.<name>.rateLimits.maxAttempts` | int | provider quota | Max requests per timespan. |
| `providers.<name>.rateLimits.timespan` | string | provider window | `second`, `minute`, `hour`, or `day`. |
| `cache.enabled` | bool | `false` | Store ETags and serve `304 Not Modified` from cache. |
| `cache.store` | string\|null | default store | Cache store used for conditional requests. |
| `cache.ttl` | int | `3600` | Cached-response TTL in seconds. |
| `logging.enabled` | bool | `false` | Log method/URL/status/duration (never tokens or bodies). |
| `logging.channel` | string\|null | default channel | Log channel for request logging. |
| `webhooks.enabled` | bool | `false` | Register the webhook receiving route. |
| `webhooks.path` | string | `git/webhooks` | Base path for `POST {path}/{provider}`. |
| `webhooks.middleware` | array | `['api']` | Middleware applied to the webhook route. |

Environment variables: `GITHUB_TOKEN`, `GITLAB_TOKEN`, `BITBUCKET_TOKEN`,
`*_WEBHOOK_SECRET`, `GIT_CACHE_ENABLED`, `GIT_LOGGING_ENABLED`, `GIT_WEBHOOKS_ENABLED`,
`GIT_WEBHOOKS_PATH`, and the per-provider `*_RETRY_TIMES` / `*_RETRY_BACKOFF` / timeout /
rate-limit keys.

Rate limiting is enforced client-side with Laravel's native rate limiter; exceeding the
configured budget throws a `RateLimitExceededException` before a request is sent.

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
`FeatureNotSupportedException`.

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

`client()` retries idempotent 429/5xx responses with backoff. With `cache.enabled`, ETags are
stored and conditional requests serve `304` responses from cache. The last response's rate
limit is exposed:

```php
$status = $github->rateLimit(); // ?RateLimitStatus { limit, remaining, used, resetAt }
```

### Clone URL

```php
$url = $github->cloneUrlForRepository('octocat/Hello-World', 'octocat', Token::from('ghp_...'));
// https://token:ghp_...@github.com/octocat/Hello-World.git
```

### Webhooks

Set `GIT_WEBHOOKS_ENABLED=true` and a `*_WEBHOOK_SECRET` per provider. Incoming requests to
`POST {webhooks.path}/{provider}` are signature-verified (GitHub `X-Hub-Signature-256`, GitLab
`X-Gitlab-Token`, Bitbucket `X-Hub-Signature`) and dispatched as events:

```php
use RoundlyConsulting\Git\Events\{WebhookReceived, PushReceived, PullRequestEventReceived};

Event::listen(PushReceived::class, function (PushReceived $event) {
    $event->event->provider; // ProviderName
    $event->event->payload;  // array
});
```

A missing or invalid signature returns `403` and dispatches nothing.

### Artisan commands

```bash
php artisan git:repos github [--json]
php artisan git:rate-limit github
php artisan git:commits github octocat/Hello-World --branch=main --since=2024-01-01
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

```php
use RoundlyConsulting\Git\Enums\Feature;

if ($github->supports(Feature::ListCommits)) {
    $github->commits('octocat/Hello-World')->get();
}
```

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
