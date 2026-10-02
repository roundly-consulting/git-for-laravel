<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/git-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=git-for-laravel">
    <img src="art/hero.png" alt="Git for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/git-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/git-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/git-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/git-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/git-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/git-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=git-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

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
| `providers.<name>.token` | string\|null | `null` | Default access token (`Git::github()` uses it). |
| `providers.<name>.webhook_secret` | string\|null | `null` | Secret used to verify incoming webhooks. |
| `providers.<name>.timeout` | int | `10` | HTTP request timeout in seconds. |
| `providers.<name>.retry` | array\|int | `{times:1, backoff:0}` | Attempts and backoff (ms) for a read that hits a 429/5xx or a dropped connection; writes are never retried. |
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
| `providers.github.app.id` | string\|null | `null` | GitHub App id; when set, `Git::github()` mints installation tokens. |
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

Everything starts at the `Git` facade. `Git::github()` (or `gitlab()`, `bitbucket()`,
`provider()`) hands you an authenticated driver; `->repo('owner/name')` scopes it to one
repository, and `->pullRequest($number)` to one pull request of it. With a token in config you
don't pass a credential at all; an explicit `Token` always overrides config.

```php
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\Dto\Credentials\Token;
use RoundlyConsulting\Git\Enums\MergeMethod;

$github = Git::github();                       // uses config('git.providers.github.token')
$github = Git::github(Token::from('ghp_...')); // explicit override
$gitlab = Git::provider('gitlab');             // by string, class-string, or ProviderName enum

$repo = Git::github()->repo('acme/app');

$repo->pullRequests('open');                   // Page<PullRequest>
$repo->commits()->branch('main')->lazy();      // LazyCollection<int, Commit>
$repo->contents('README.md');                  // FileContent (decoded)
$repo->compare('main', 'feature/ci');          // Comparison
$repo->releases();                             // Page<Release>

$pr = $repo->pullRequest(12);
$pr->approve('LGTM');
$pr->merge(MergeMethod::Squash, sha: $pr->get()->raw()['head']['sha'] ?? null);
```

With no token configured the provider is **unauthenticated** and reads public repositories
anonymously — no `Authorization` header is sent at all (GitHub answers an empty `Bearer` as a bad
credential). Anonymous callers get the forge's anonymous quota (60 requests an hour on GitHub).

**Errors.** A `401` — the forge refusing the credential itself — throws `InvalidCredentialsException`:
"requires authentication" when none was configured, "rejected the credential" when the token is
invalid, revoked, or expired (the forge's response is the exception's `getPrevious()`). Every other
refusal stays Laravel's `RequestException`, so you can read its status: a repository you cannot see
is a `404` (GitHub hides private repositories from callers without access), and a throttled `403`
or `429` stays retryable rather than being mistaken for a broken credential.

### The API at a glance

| Where | Methods |
| --- | --- |
| `Git::` (the `GitManager`) | `github(?Credentials)`, `githubApp(?GithubApp)`, `gitlab(?Credentials)`, `bitbucket(?Credentials)`, `provider(name, ?Credentials)`, `capabilities(name)`, `credentials(name)`, `verifyWebhook(name, Request)`, `fake()` |
| a driver (`Git::github()`) | `repo(path\|Repository)`, `installations()`, `user()`, `repositories()`, `allRepositories()`, `searchRepositories()`, `createRepository()`, `installationRepositories()`, `allInstallationRepositories()`, `batch()`, `rateLimit()`, `supports()` / `supportsAll()` / `supportsAny()` / `capabilities()` / `features()` / `featureMatrix()` / `featureInfo()`, `authenticate()`, `isAuthenticated()` |
| `->repo('acme/app')` | `get()`, `path()`, `branches()`, `createBranch()`, `commit($sha)`, `commits()`, `pullRequests()`, `pullRequest($n)`, `createPullRequest()`, `issues()`, `issue($n)`, `comment()`, `tags()`, `createTag()`, `releases()`, `release()`, `createRelease()`, `contents()`, `createFile()`, `updateFile()`, `compare()`, `contributors()`, `languages()`, `webhooks()`, `cloneUrl()` |
| `->pullRequest(12)` | `get()`, `number()`, `merge()`, `approve()`, `review()`, `reviews()`, `close()`, `comment()` |
| `Git::githubApp()->installations()` | `all()`, `find($id)`, `forOrganization($org)`, `forUser($login)`, `installUrl(?$state)` |
| `->repo(...)->webhooks()` | `register()`, `all()`, `registered()`, `delete()`, `deleteByUrl()` |

### Without the facade

Every facade call is a call on `GitManager`, the container singleton behind it — inject it
instead if you prefer constructor injection. `Git::fake()` swaps the injected instance too.

```php
use RoundlyConsulting\Git\GitManager;

final class ShipRelease
{
    public function __construct(private GitManager $git) {}

    public function __invoke(string $repository, int $number): string
    {
        return $this->git->github()->repo($repository)->pullRequest($number)->merge();
    }
}
```

git is a remote-API client, so there are no action classes: the drivers *are* the use cases.
The handles are thin — every handle method is the matching flat method on the driver's
`Interfaces\Provider` contract with the path filled in — so you can also call that layer
directly. It is the contract a custom driver implements and the fake doubles:

```php
$github = app(GitManager::class)->github();

$github->mergePullRequest('acme/app', 12, MergeMethod::Squash); // same call as ->repo()->pullRequest(12)->merge()
$github->contents('acme/app', 'README.md', ref: 'main');
```

### Scoped handles refuse to leave their scope

Every path a handle takes ends up inside a forge URL, so the handles check it first and throw
`OutOfScopeException` (an `InvalidArgumentException`) instead of addressing some other resource:

- `repo()` refuses an empty path, an empty, `.` or `..` segment, and `?`, `#`, `\` or whitespace
  — and a `Repository` object that belongs to another provider (a GitLab repository on
  `Git::github()`).
- `contents()`, `createFile()` and `updateFile()` refuse file paths with `.`/`..`/empty segments,
  `?`, `#` or `\`; `commit()`, `release()` and `compare()` refuse such refs.
- `pullRequest()` refuses a number below 1; `installations()->find()` a non-numeric id;
  `forOrganization()` / `forUser()` anything but a single segment.

```php
Git::github()->repo('acme/app/../billing'); // OutOfScopeException
Git::github()->repo($gitlabRepository);     // OutOfScopeException: belongs to GitLab
```

The flat driver methods take their arguments as given — validate there yourself if you build
paths from user input and skip the handles.

### The authenticated user

```php
$owner = Git::github()->user();

$owner->id;     // "1"
$owner->name;   // "octocat"
$owner->avatar; // "https://github.com/images/error/octocat_happy.gif"
```

### Repositories (paginated)

List endpoints return a `Page` and never silently truncate. Use `allRepositories()` for a
lazily auto-paginating `LazyCollection`.

```php
$github = Git::github();

$page = $github->repositories(perPage: 50); // Page<Repository>
$page->items;     // list<Repository>
$page->hasMore;   // bool
$page->collect(); // Collection<Repository>

foreach ($github->allRepositories() as $repository) {
    // streams across all pages, one request at a time
}

$repository = $github->repo('octocat/Hello-World')->get();
$repository->name;          // "Hello-World"
$repository->defaultBranch; // "master"

$github->repo($repository)->releases();     // a returned Repository opens its own handle
```

### Branches and commits (fluent query)

```php
$repo = Git::github()->repo('octocat/Hello-World');

$branches = $repo->branches()->items; // ['main', 'dev']

$commits = $repo->commits()                      // CommitQuery
    ->branch('feature/new ui')                   // properly URL-encoded
    ->author('octocat')
    ->path('src/')
    ->since(now()->subWeek())
    ->until(now())
    ->perPage(50)
    ->get();                                     // Page<Commit>

foreach ($repo->commits()->branch('main')->lazy() as $commit) {
    // auto-paginated stream
}

$commit = $repo->commit('6dcb09b...');
```

GitHub and GitLab apply every filter. Bitbucket's commits endpoint filters by `branch()` and
`path()` only, so on Bitbucket `author()`, `since()` and `until()` throw
`FeatureNotSupportedException` when the query runs, rather than returning unfiltered commits.

### More read endpoints

```php
$repo = Git::github()->repo('octocat/Hello-World');

$repo->pullRequests();                     // Page<PullRequest>  (GitLab MRs, Bitbucket PRs)
$repo->pullRequests('closed', perPage: 50);
$repo->pullRequest(7)->get();              // PullRequest
$repo->issues();                           // Page<Issue>
$repo->issue(3);                           // Issue
$repo->tags();                             // Page<Tag>
$repo->releases();                         // Page<Release>
$repo->release('v1.0');
$repo->contents('README.md', ref: 'main'); // FileContent (decoded)
$repo->compare('main', 'feature');         // Comparison
$repo->contributors();                     // Page<Contributor>
$repo->languages();                        // ['PHP' => 12345, ...]

Git::github()->searchRepositories('laravel'); // Page<Repository>
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

$pr = Git::github()->repo('octocat/Hello-World')->pullRequest(1)->get();

$pr->state;                 // ResourceState::Open — identical across GitHub/GitLab/Bitbucket
$pr->raw()['mergeable'];    // any unmodelled provider field, still reachable
```

### Concurrent fetches with batch()

`batch()` fans a list of reads out over a single `Http::pool()` round trip, returning a keyed
`BatchResult` that separates successes from per-key failures (no exception unless you ask for
one). Pooled requests carry the same authentication as single requests but bypass the ETag
conditional cache.

```php
$github = Git::github();

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

$created = Git::github()->createRepository(new NewRepository(name: 'acme', private: true));

$repo = Git::github()->repo('acme/acme');
$repo->createBranch(new NewBranch('feature/ci', 'main'));
$repo->createFile(new NewFile('ci.yml', '...', 'Add CI', 'feature/ci'));
$pr = $repo->createPullRequest(new NewPullRequest('Add CI', 'feature/ci', 'main'));
$repo->pullRequest($pr->number)->comment('LGTM');   // or $repo->comment(new NewComment($pr->number, 'LGTM'))
$repo->createRelease(new NewRelease('v1.0', 'First release'));
$repo->createTag(new NewTag('v1.0.1', 'main'));
```

#### Creating a repository

`createRepository()` picks its route from the input: a `template` generates from a template
repository (`POST /repos/{template}/generate`), an `owner` creates in that organization
(`POST /orgs/{owner}/repos`), and neither creates under the authenticated account
(`POST /user/repos`).

```php
$repo = Git::github()->createRepository(new NewRepository(
    name: 'widget-for-laravel',
    private: true,
    owner: 'acme',
    template: 'acme/package-template',
));
```

Only the template route produces a repository that already has a **commit** — the other two
create an empty one unless you pass `autoInit: true`, and cloning an empty repository with
`--branch` fails. `defaultBranch` is honoured by renaming the initial branch after creation,
because GitHub accepts no `default_branch` at creation time; it therefore requires `autoInit`
or a `template`.

Creating in an organization needs the App to hold organization `administration: write` — an
org owner approves it on the existing installation. Mint the token for it just-in-time:

```php
use RoundlyConsulting\Git\Dto\Input\InstallationTokenScope;

$credentials = GithubAppToken::for($appId, $installationId, $privateKey)
    ->forScope(InstallationTokenScope::administrationOnly());
```

GitLab maps `owner` to a numeric `namespace_id` and Bitbucket to the workspace; template
generation is GitHub-only and answers `FeatureNotSupportedException` elsewhere.

#### Closing, approving, and merging a pull request

```php
use RoundlyConsulting\Git\Enums\MergeMethod;

$pr = Git::github()->repo('acme/acme')->pullRequest($number);

$pr->close();           // PullRequest, state closed — never merges
$pr->approve('LGTM');   // the review's own state
$sha = $pr->merge(
    MergeMethod::Squash, // Merge | Squash | Rebase — a repository may disallow any of them
    sha: $headSha,       // the head commit you decided about
);
```

Three things worth knowing before you wire this up:

- **A merge the forge refuses THROWS.** `405` (branch protection, a required check, a merge
  method the repository disallows) and `409` (conflict, or `sha` no longer matching) arrive as
  `RequestException` — a `200` from that endpoint always means it merged, so there is no
  `merged: false` to inspect. Read the exception's status to tell "the provider said no" from
  "the call failed".
- **Pass `sha`** — the head commit you decided about. Without it, a push landing between the
  review and the merge is merged unseen; with it, that answers `409`.
- **GitHub refuses an account approving its own pull request.** For an App, every PR the App
  opened is its own, so a service that authors PRs cannot also approve them.

GitLab and Bitbucket answer `FeatureNotSupportedException` for all three — check
`supports(Feature::MergePullRequest)` if you drive more than one forge.

#### Reviewing a pull request

A whole review in one call — a verdict, a summary, and inline comments anchored to the diff:

```php
use RoundlyConsulting\Git\Dto\Input\{NewReview, NewReviewComment};
use RoundlyConsulting\Git\Enums\{DiffSide, ReviewEvent};

$pr = Git::github()->repo('acme/acme')->pullRequest($number);

$review = $pr->review(new NewReview(
    event: ReviewEvent::Comment,                    // Comment | Approve | RequestChanges
    body: 'Two findings, one blocking.',
    comments: [
        new NewReviewComment('app/Foo.php', 42, 'This nulls out on the retry.'),
        new NewReviewComment('app/Bar.php', 7, 'Deleted line, so anchor it left.', DiffSide::Left),
        // A finding about a block: lines 30–36, inclusive.
        new NewReviewComment('app/Baz.php', 36, 'This whole branch is unreachable.', startLine: 30),
    ],
));
```

Reading back what has been said in review:

```php
$reviews = $pr->reviews();

$reviews->reviews;                    // list<PullRequestReview>        — state, body, author, submittedAt
$reviews->comments;                   // list<PullRequestReviewComment> — body, path, line, side, reviewId
$reviews->isEmpty();                  // nobody has reviewed it at all

$latest = $reviews->latest();         // the newest SUBMITTED review, or null
$latest?->state;                      // 'APPROVED' | 'CHANGES_REQUESTED' | 'COMMENTED' | 'DISMISSED' | …
$reviews->commentsFor($latest);       // just the findings that review published
```

- **One request, not one per comment.** GitHub publishes a review atomically; posting the
  comments separately leaves half a review on the pull request when anything fails partway.
- **`Approve` and `RequestChanges` are refused on your own pull request** (`422`), exactly as
  `approve()` is. An account that authors pull requests can only ever `Comment` on its own
  work — so put the verdict a reader acts on in the **body**.
- **A comment anchored off the diff is also a `422`** — the line was never changed. It arrives
  as the same `RequestException`; you can tell the two apart by whether you sent comments.
  `startLine` must come *before* `line`; an inverted or collapsed span is rejected locally,
  since GitHub's own message never says to swap them.
- **Use `latest()`, not `end($reviews->reviews)`.** GitHub serves reviews oldest-first, but a
  *pending draft* has no `submittedAt` and still sorts last — so the naive read hands back a
  verdict nobody published. Both endpoints are walked to the end (`maxPages`, 5 by default),
  because one page of a long thread is its oldest reviews.
- **A read keeps what it does not know.** `submittedAt` is null for a pending draft, `line` is
  null on an outdated comment whose anchor GitHub dropped (`$comment->isOutdated()`), and
  `createdAt` is null rather than *now* when the payload carries no timestamp.

### Resilience, caching, and rate limits

Reads (GET) retry a dropped connection, a `429`, or a `5xx` with backoff (`retry.times` attempts);
a `4xx` is never retried, and a write is never retried at all — a `5xx` on a write may already
have landed, and a second POST would open a second pull request. Requests are paced by the client-side rate
limiter (see [Configuration](#configuration)) — waiting when a window is exhausted, failing fast
with `RateLimitExceededException` only when a `max_wait` is set, and adapting to the provider's
own `Retry-After` headers. With `cache.enabled`, ETags are stored and conditional requests serve
`304` responses from cache. The last response's server-reported rate limit is exposed:

```php
$status = Git::github()->rateLimit(); // ?RateLimitStatus { limit, remaining, used, resetAt }
```

### Clone URL

```php
$url = Git::github()->repo('octocat/Hello-World')->cloneUrl('octocat', Token::from('ghp_...'));
// https://token:ghp_...@github.com/octocat/Hello-World.git  (GitHub uses its own username)
```

To clone with the credential git itself is configured with, read it off the manager:

```php
use RoundlyConsulting\Git\Enums\ProviderName;

$credentials = Git::credentials(ProviderName::Github); // ?Credentials — app installation, else token, else null
$url = Git::github()->repo('octocat/Hello-World')->cloneUrl('octocat', $credentials);
```

A refreshable credential is asked for a live token instead of being read for a static one, and
an installation token gets GitHub's documented username:

```php
$url = Git::github()->repo('octocat/Hello-World')->cloneUrl('octocat', $installationCredentials);
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

$github = Git::github(GithubAppToken::for(
    appId: config('git.providers.github.app.id'),
    installationId: config('git.providers.github.app.installation_id'),
    privateKey: config('git.providers.github.app.private_key'), // PEM string or file path
));
$github->repositories(); // installation token minted, cached to expiry, reused

// The OAuth client (`client_id`, `client_secret`, `token_url`) is static per provider, so
// `forProvider()` takes it from `git.providers.github.oauth.*` and asks only for the
// per-user half. Pass all five to `OauthToken::for()` to bypass config entirely.
$github = Git::github(OauthToken::forProvider(
    ProviderName::Github,
    accessToken: $access, refreshToken: $refresh, expiresAt: $expiresAt,
));
```

When `git.providers.github.app.id` is configured, `Git::github()` builds a `GithubAppToken`
automatically — no explicit credential needed. `Git::credentials(ProviderName::Github)` returns
that same credential, and `->accessToken()` on it reads the live installation token (minting it
when the cache is cold). Use a shared cache store (not the `array` driver) so minted tokens
persist across requests.

#### Persisting a rotated refresh token

**If you store OAuth credentials, listen for `OauthTokenRefreshed`.** Providers that rotate
refresh tokens invalidate the old one at the moment they issue a new one. The new token goes
into the token cache, whose entry expires with the *access* token — so once that hour is up,
the only copy left anywhere is the one you persisted, and it is dead. The connection then
breaks with nothing having told you why.

```php
use RoundlyConsulting\Git\Events\OauthTokenRefreshed;

Event::listen(OauthTokenRefreshed::class, function (OauthTokenRefreshed $event) {
    if ($event->rotated()) {
        $connection->update([
            'refresh_token' => $event->refreshToken,
            'access_token' => $event->accessToken,
            'expires_at' => $event->expiresAt,
        ]);
    }
});
```

The event fires on every refresh, rotated or not; `rotated()` is the flag that says the stored
value has to change. Do not log the event whole — it carries live tokens by design.

#### Repository-scoped tokens

An installation token with no scope reaches **every** repository the app is installed on, for
an hour. Narrow it per operation instead — this is the point of minting rather than storing a
credential:

```php
use RoundlyConsulting\Git\Dto\Input\InstallationTokenScope;

$scoped = GithubAppToken::for(appId: $id, installationId: $installation, privateKey: $key)
    ->forScope(InstallationTokenScope::forRepositories(repositoryIds: ['40823311']));

Git::github($scoped)->repo('acme/api')->cloneUrl('acme', $scoped);
```

`forRepositories()` takes the permission set from `git.providers.github.app.permissions`.
Either selector works — `repositoryIds` (numeric, survives a rename) or `repositories`
(names) — and they are equally narrow.

An **empty** scope is refused outright: a scope object that names no repository would mint a
token for every repository in the installation, so the token is never minted.

The one operation that genuinely cannot name a repository is asking which repositories an
installation has. Use `InstallationTokenScope::metadataOnly()` there — wide on the repository
axis, `metadata: read` on the other:

```php
Git::github($credentials->forScope(InstallationTokenScope::metadataOnly()))
    ->installationRepositories();
```

Passing **no scope at all** (`scope === null`) means "everything this installation granted,
everywhere", and is almost never what you want. The token cache is keyed
per scope, so a scoped mint can never be served a wider cached token. A scope the installation
cannot satisfy (unknown repository, ungranted permission) and a vanished installation raise
`InvalidCredentialsException`; a `403` (rate limit, suspension) stays a `RequestException`,
because consumers treat the former as "reconnect required".

#### Acting as the app, and installing it

`/app/**` endpoints authenticate with the app's own JWT rather than an installation token.
That is how you verify an installation id before trusting it — for instance one that arrived
from a browser redirect.

`Git::githubApp()` reads `git.providers.github.app.{id,private_key}` and throws
`InvalidCredentialsException` naming the missing key when either is absent. The `/app/**`
endpoints require **app** credentials and the installation endpoints require an
**installation** credential; using the wrong one raises this package's own exception rather
than GitHub's opaque 403.

```php
$installation = Git::githubApp()->installations()->find($installationIdFromTheRedirect);

$installation->accountLogin;            // "acme-inc"
$installation->repositorySelection;     // "all" | "selected"
$installation->reachesEveryRepository();
$installation->isSuspended();

Git::github($credentials)->installationRepositories(); // NOT /user/repos: an
                                                       // installation token 403s there
```

The other app-JWT lookups — every account the app is installed on, and finding an existing
installation by account rather than by id (useful when a customer reinstalls and the stored
id goes stale):

```php
$installations = Git::githubApp()->installations();

$installations->all();                      // Page<Installation>
$installations->forOrganization('acme-inc'); // Installation
$installations->forUser('octocat');          // Installation
```

All of these are callable on the `Provider` type without an `instanceof`, drivable through
`Git::fake()`, and answer `FeatureNotSupportedException` on GitLab and Bitbucket.

Send a human to install the app with `installUrl()`; GitHub echoes `state` back to the app's
Setup URL alongside `installation_id`, which is what ties the redirect that returns to the
request that left:

```php
$url = Git::githubApp()->installations()->installUrl($state); // https://github.com/apps/<slug>/installations/new?state=…
```

### Webhooks

Set `GIT_WEBHOOKS_ENABLED=true` and a `*_WEBHOOK_SECRET` per provider. Incoming requests to
`POST {webhooks.path}/{provider}` are signature-verified (GitHub `X-Hub-Signature-256`, GitLab
`X-Gitlab-Token`, Bitbucket `X-Hub-Signature`) and dispatched as events.

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

**Your own route.** Keep `GIT_WEBHOOKS_ENABLED=false` and run the same check yourself:

```php
use Illuminate\Http\Request;
use RoundlyConsulting\Git\Enums\ProviderName;

Route::post('/hooks/github', function (Request $request) {
    abort_unless(Git::verifyWebhook(ProviderName::Github, $request), 403);

    // … your handling
});
```

`verifyWebhook()` reads `git.providers.<name>.webhook_secret`; with no secret configured it
answers `false` — nothing verifies, rather than everything.

### Webhook auto-registration

`repo(...)->webhooks()` ties this app's inbound route to the provider's outbound create-webhook
op: it derives the URL from the published `git.webhooks` route, defaults the secret to the
configured `webhook_secret`, and is idempotent (a hook with the same URL is never created
twice).

```php
$webhooks = Git::github()->repo('acme/api')->webhooks();

$webhooks->register();                                 // returns the existing or new Webhook
$webhooks->register(events: ['push', 'pull_request']);
$webhooks->all();                                      // list<Webhook>
$webhooks->registered($url);                           // bool
$webhooks->delete($id);
$webhooks->deleteByUrl($url);
```

### Artisan commands

```bash
php artisan git:repos github [--json]
php artisan git:rate-limit github
php artisan git:commits github octocat/Hello-World --branch=main --since=2024-01-01
php artisan git:webhook github acme/api [--url=] [--events=push] [--secret=] [--list] [--delete=ID]
```

### Testing without real HTTP

`Git::fake()` swaps the manager for a recording `GitFake` — for the facade **and** for anything
that injected `GitManager`. Every driver it hands out is a seedable `ProviderFake`, no HTTP
leaves the process (`Http::preventStrayRequests()`), and every call is recorded — whether it
went through the flat driver methods, a `repo()` / `pullRequest()` / `installations()` handle,
`webhooks()` or `batch()`.

```php
use RoundlyConsulting\Git\Facades\Git;
use RoundlyConsulting\Git\Enums\ProviderName;

$fake = Git::fake();
$fake->github()->seedRepositories([$repositoryDto])->seedMergeCommit('abc123');

// run code that calls Git::github()->repo('acme/app')->pullRequest(12)->merge() ...

Git::assertSent(ProviderName::Github, 'mergePullRequest');
Git::assertSent(ProviderName::Github, 'mergePullRequest', fn (string $path, int $number) => $number === 12);
Git::assertSentTimes(ProviderName::Github, 'approvePullRequest', 1);
Git::assertNotSent(ProviderName::Github, 'closePullRequest');
Git::assertNothingSent(ProviderName::Gitlab);
Git::assertBatched(ProviderName::Github, 'languages');
Git::assertNotBatched(ProviderName::Github, 'repositories');
Git::assertRepositoryCreated('new-repo', owner: 'acme', template: 'acme/package-template');
Git::assertNoRepositoryCreated();

Git::recorded(ProviderName::Github, 'mergePullRequest'); // list<RecordedCall> — method + arguments
```

Every assert names the **driver method** the call reached (the handles call the flat
methods), and an `assertSent()` / `assertNotSent()` callback receives that method's arguments
positionally. `assertNothingSent()` without a provider checks every provider at once.

The double answers the **whole** `Provider` contract — every read, write, installation lookup,
and the commit query — so a host application never hits an "undefined method" as it grows. Three
rules make its behaviour predictable:

| Kind of call | Unseeded behaviour |
| --- | --- |
| List reads (`pullRequests`, `issues`, `tags`, `releases`, `contributors`, `branches`, `languages`) | an **empty page** — a real provider answer |
| Single-resource reads (`repository`, `commit`, `contents`, `issue`, `release`, `installation`) | **throws**, naming the seeder to call |
| Writes (`createPullRequest`, `comment`, `mergePullRequest`, …) | **synthesized from the input** |

Each fake driver supports exactly what its real driver supports: `supports()`, `capabilities()`
and `featureMatrix()` answer the same, and an operation the forge lacks throws the same
`FeatureNotSupportedException` before anything is recorded — a fake Bitbucket refuses
`->pullRequest(1)->merge()` just as Bitbucket does, so a test cannot pass against a flow
production rejects.

Seeders, all chainable: `seedRepositories` `seedRepository` `seedCreatedRepository`
`seedCommits` `seedCommit` `seedBranches` `seedPullRequests` `seedPullRequest` `seedIssues`
`seedIssue` `seedTags` `seedReleases` `seedRelease` `seedContents` `seedComparison`
`seedContributors` `seedLanguages` `seedSearchResults` `seedComment` `seedMergeCommit`
`seedApprovalState` `seedSubmittedReview` `seedPullRequestReviews` `seedUser`
`seedInstallation` `seedInstallations` `seedWebhooks` `seedCreatedWebhook` `seedBatch`.

`review()` needs no seeding: it answers the state the event actually means
(`Comment` → `COMMENTED`, never `APPROVED`), so a host asserting on the verdict cannot pass
against a fake that would fail against GitHub. `seedSubmittedReview()` overrides that when a
test needs a specific id or url back. `seedPullRequestReviews($reviews, $comments)` seeds the
two halves separately, because the real call reads them from two endpoints.

```php
$fake->github()
    ->seedPullRequest($pullRequestDto)
    ->seedMergeCommit('abc123');

// ->pullRequest(n)->close() returns the seeded PR with state Closed; ->merge() returns 'abc123'
```

A few reads fall back on purpose rather than answering empty: `installationRepositories()` reads
the same bucket as `repositories()`, `installations()->all()` stands in the single seeded
installation, `searchRepositories()` falls back to the repository bucket, and `pullRequest()` /
`issue()` / `release()` take the first of their list. An unseeded pull request answers with the
number you asked for.

### Feature detection

Branch on the capability matrix instead of catching `FeatureNotSupportedException`:

```php
use RoundlyConsulting\Git\Enums\Feature;

$github = Git::github();

if ($github->supports(Feature::ListCommits)) {
    $github->repo('octocat/Hello-World')->commits()->get();
}

$github->supportsAll(Feature::CreateRelease, Feature::CreateTag); // bool
$github->supportsAny(Feature::Languages);                         // bool
$github->capabilities();                                          // ['repositories' => true, ...]
$github->featureMatrix();                                         // list<FeatureInfo> with ->supported
Git::capabilities(ProviderName::Bitbucket);                       // without authenticating
```

### Extending the manager

`GitManager` is macroable, so you can register your own provider shortcuts:

```php
Git::macro('myHost', fn ($credentials = null) => $this->provider(MyProvider::class, $credentials));
```

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=git-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=git-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
