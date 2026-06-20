# Git for Laravel

Access git repositories on **GitHub**, **GitLab**, and **Bitbucket** through a single,
Laravel-native API. Authenticate with a token, then list repositories, read a single
repository, list branches, page through commits, fetch a single commit, and build an
authenticated clone URL — all returning typed data objects.

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

## Configuration

The published `config/git.php` holds one block per provider. Every value is backed by an
environment variable, and the package works with zero configuration.

```php
return [
    'providers' => [
        'github' => [
            'url' => env('GITHUB_API_URL', 'https://api.github.com'),
            'timeout' => env('GITHUB_TIMEOUT', 10),
            'retry' => env('GITHUB_RETRY', 1),
            'rateLimits' => [
                'owner' => env('GITHUB_RATELIMIT_OWNER', 'app'),
                'maxAttempts' => env('GITHUB_RATELIMIT', 5000),
                'timespan' => env('GITHUB_RATELIMIT_TIMESPAN', 'hour'),
            ],
            'options' => [
                'headers' => [
                    'User-Agent' => env('GIT_USER_AGENT', env('APP_NAME', 'GitHttp/1.0')),
                    'X-GitHub-Api-Version' => env('GITHUB_API_VERSION', '2022-11-28'),
                ],
            ],
        ],
        // gitlab and bitbucket follow the same shape.
    ],
];
```

| Key | Type | Default | Purpose |
|---|---|---|---|
| `providers.<name>.url` | string | provider API base URL | Base URL for the provider's API. |
| `providers.<name>.timeout` | int | `10` | HTTP request timeout in seconds. |
| `providers.<name>.retry` | int | `1` | Number of HTTP retry attempts. |
| `providers.<name>.rateLimits.owner` | string | `app` | Throttle bucket key. |
| `providers.<name>.rateLimits.maxAttempts` | int | provider quota | Max requests per timespan. |
| `providers.<name>.rateLimits.timespan` | string | provider window | `second`, `minute`, `hour`, or `day`. |
| `providers.<name>.options` | array | headers | Extra options forwarded to the HTTP client. |

Rate limiting is enforced client-side with Laravel's native rate limiter; exceeding the
configured budget throws a `RateLimitExceededException` before a request is sent.

## Usage

Resolve a provider through the `Registry` facade and pass a credentials object. GitHub,
GitLab, and Bitbucket all authenticate with a `Token`.

```php
use RoundlyConsulting\Git\Facades\Registry;
use RoundlyConsulting\Git\Dto\Credentials\Token;

$github = Registry::github(Token::from('ghp_your_token'));
```

### The authenticated user

```php
$owner = $github->user();

$owner->id;     // "1"
$owner->name;   // "octocat"
$owner->avatar; // "https://github.com/images/error/octocat_happy.gif"
```

### Repositories

```php
$repositories = $github->repositories();          // Collection<Repository>
$repository = $github->repository('octocat/Hello-World');

$repository->name;          // "Hello-World"
$repository->path;          // "octocat/Hello-World"
$repository->defaultBranch; // "master"
$repository->owner;         // Owner DTO
$repository->createdAt;     // Carbon
```

### Branches and commits

```php
$branches = $github->branches('octocat/Hello-World');          // ['main', 'dev']

$commits = $github->commits('octocat/Hello-World', 'main', page: 1); // Collection<Commit>
$commit = $github->commit('octocat/Hello-World', '6dcb09b...');

$commit->sha;
$commit->message;
$commit->author->name;  // Author DTO
$commit->commitAt;      // Carbon
```

### Clone URL

```php
$url = $github->cloneUrlForRepository(
    path: 'octocat/Hello-World',
    username: 'octocat',
    credentials: Token::from('ghp_your_token'),
);
// https://token:ghp_your_token@github.com/octocat/Hello-World.git
```

### Feature detection

Each provider advertises the features it supports, so you can branch on capability instead
of catching exceptions:

```php
use RoundlyConsulting\Git\Dto\Feature;

if ($github->supports(Feature::ListCommits)) {
    $github->commits('octocat/Hello-World', 'main');
}
```

Calling an unsupported feature throws a `FeatureNotSupportedException`. Authenticating with
an unsupported credential type throws an `InvalidCredentialsException`.

### Resolving by class

```php
use RoundlyConsulting\Git\Providers\Gitlab;

$gitlab = Registry::provider(Gitlab::class, Token::from('glpat-...'));
```

### Extending the registry

`Registry` is macroable, so you can register your own provider shortcuts:

```php
use RoundlyConsulting\Git\Facades\Registry;

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
