<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Providers;

use Closure;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use RoundlyConsulting\Crypto\Hash\Digest;
use RoundlyConsulting\Git\Batch\Batch;
use RoundlyConsulting\Git\Batch\BatchError;
use RoundlyConsulting\Git\Concerns\InteractsWithRateLimits;
use RoundlyConsulting\Git\Contracts\RefreshableCredentials;
use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Comparison;
use RoundlyConsulting\Git\Dto\Contributor;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
use RoundlyConsulting\Git\Dto\Credentials\GithubApp;
use RoundlyConsulting\Git\Dto\Credentials\GithubAppToken;
use RoundlyConsulting\Git\Dto\Credentials\OauthToken;
use RoundlyConsulting\Git\Dto\FeatureInfo;
use RoundlyConsulting\Git\Dto\FileContent;
use RoundlyConsulting\Git\Dto\Input\NewBranch;
use RoundlyConsulting\Git\Dto\Input\NewComment;
use RoundlyConsulting\Git\Dto\Input\NewFile;
use RoundlyConsulting\Git\Dto\Input\NewPullRequest;
use RoundlyConsulting\Git\Dto\Input\NewRelease;
use RoundlyConsulting\Git\Dto\Input\NewRepository;
use RoundlyConsulting\Git\Dto\Input\NewTag;
use RoundlyConsulting\Git\Dto\Input\NewWebhook;
use RoundlyConsulting\Git\Dto\Input\UpdatedFile;
use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Dto\Issue;
use RoundlyConsulting\Git\Dto\Owner;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\RateLimitStatus;
use RoundlyConsulting\Git\Dto\Release;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Dto\Tag;
use RoundlyConsulting\Git\Dto\Webhook;
use RoundlyConsulting\Git\Enums\Feature;
use RoundlyConsulting\Git\Enums\ProviderName;
use RoundlyConsulting\Git\Exceptions\FeatureNotSupportedException;
use RoundlyConsulting\Git\Exceptions\InvalidCredentialsException;
use RoundlyConsulting\Git\Http\ConditionalCache;
use RoundlyConsulting\Git\Http\RateLimitStatusParser;
use RoundlyConsulting\Git\Interfaces\Provider;
use RoundlyConsulting\Git\Mapping\ResourceMapper;
use RoundlyConsulting\Git\Webhooks\Webhooks;

abstract class BaseProvider implements Provider
{
    use InteractsWithRateLimits;

    protected ?Credentials $authentication = null;

    protected ?RateLimitStatus $rateLimit = null;

    /**
     * The config key for this provider (github | gitlab | bitbucket).
     */
    abstract protected function key(): string;

    abstract protected function cloneBaseUrl(): string;

    abstract protected function mapper(): ResourceMapper;

    public function name(): string
    {
        return $this->providerName()->label();
    }

    public function description(): string
    {
        return "{$this->name()} Provider";
    }

    public function providerName(): ProviderName
    {
        return ProviderName::from($this->key());
    }

    public function user(): Owner
    {
        return new Owner(
            id: 'unknown',
            name: 'Unknown',
            avatar: null,
        );
    }

    public function supports(Feature $feature): bool
    {
        return in_array($feature, $this->features(), true);
    }

    /** @return list<Feature> */
    public function features(): array
    {
        return [];
    }

    /** @return list<FeatureInfo> */
    public function featureInfo(): array
    {
        return array_map(fn (Feature $feature): FeatureInfo => $feature->info(), $this->features());
    }

    /**
     * Every feature mapped to whether this provider supports it.
     *
     * @return array<string, bool>
     */
    public function capabilities(): array
    {
        $capabilities = [];

        foreach (Feature::cases() as $feature) {
            $capabilities[$feature->value] = $this->supports($feature);
        }

        return $capabilities;
    }

    public function supportsAll(Feature ...$features): bool
    {
        foreach ($features as $feature) {
            if (! $this->supports($feature)) {
                return false;
            }
        }

        return true;
    }

    public function supportsAny(Feature ...$features): bool
    {
        foreach ($features as $feature) {
            if ($this->supports($feature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every feature flagged supported/unsupported for this provider.
     *
     * @return list<FeatureInfo>
     */
    public function featureMatrix(): array
    {
        return array_map(
            fn (Feature $feature): FeatureInfo => $feature->info($this->supports($feature)),
            Feature::cases(),
        );
    }

    /** @return list<class-string<Credentials>> */
    public function authenticationMethods(): array
    {
        return [];
    }

    public function authenticate(Credentials $credentials): self
    {
        $this->guardAgainstInvalidCredentialsType($credentials);

        $this->authentication = $credentials;

        return $this;
    }

    public function isAuthenticated(): bool
    {
        return ! is_null($this->authentication);
    }

    public function rateLimit(): ?RateLimitStatus
    {
        return $this->rateLimit;
    }

    public function batch(): Batch
    {
        return new Batch($this);
    }

    public function mapResource(): ResourceMapper
    {
        return $this->mapper();
    }

    public function repositoryUrl(string $path): string
    {
        $this->featureNotSupported();
    }

    public function languagesUrl(string $path): string
    {
        $this->featureNotSupported();
    }

    public function pullRequestUrl(string $path, int $number): string
    {
        $this->featureNotSupported();
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function contentsRequest(string $path, string $filePath, ?string $ref = null): array
    {
        $this->featureNotSupported();
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, int>
     */
    public function normalizeLanguages(array $raw): array
    {
        /** @var array<string, int> $languages */
        $languages = array_map(fn (mixed $value): int => (int) $value, $raw);

        return $languages;
    }

    /** @param array<string, mixed> $raw */
    public function mapFileContent(array $raw): FileContent
    {
        $this->featureNotSupported();
    }

    public function repository(string $path): Repository
    {
        $this->featureNotSupported();
    }

    /** @return Page<Repository> */
    public function repositories(int $perPage = 30): Page
    {
        $this->featureNotSupported();
    }

    /** @return LazyCollection<int, Repository> */
    public function allRepositories(int $perPage = 30): LazyCollection
    {
        $this->featureNotSupported();
    }

    /** @return Page<string> */
    public function branches(string $path, int $perPage = 30): Page
    {
        $this->featureNotSupported();
    }

    public function commit(string $path, string $commit): Commit
    {
        $this->featureNotSupported();
    }

    public function cloneUrlForRepository(string $path, string $username, Credentials $credentials): string
    {
        $this->featureNotSupported();
    }

    /** @return Page<Repository> */
    public function installationRepositories(int $perPage = 30): Page
    {
        $this->featureNotSupported();
    }

    /** @return LazyCollection<int, Repository> */
    public function allInstallationRepositories(int $perPage = 30): LazyCollection
    {
        $this->featureNotSupported();
    }

    public function installation(string $id): Installation
    {
        $this->featureNotSupported();
    }

    public function installUrl(?string $state = null): string
    {
        $this->featureNotSupported();
    }

    /** @return Page<PullRequest> */
    public function pullRequests(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->featureNotSupported();
    }

    public function pullRequest(string $path, int $number): PullRequest
    {
        $this->featureNotSupported();
    }

    /** @return Page<Issue> */
    public function issues(string $path, string $state = 'open', int $perPage = 30): Page
    {
        $this->featureNotSupported();
    }

    public function issue(string $path, int $number): Issue
    {
        $this->featureNotSupported();
    }

    /** @return Page<Tag> */
    public function tags(string $path, int $perPage = 30): Page
    {
        $this->featureNotSupported();
    }

    /** @return Page<Release> */
    public function releases(string $path, int $perPage = 30): Page
    {
        $this->featureNotSupported();
    }

    public function release(string $path, string $tagOrId): Release
    {
        $this->featureNotSupported();
    }

    public function contents(string $path, string $filePath, ?string $ref = null): FileContent
    {
        $this->featureNotSupported();
    }

    public function compare(string $path, string $base, string $head): Comparison
    {
        $this->featureNotSupported();
    }

    /** @return Page<Contributor> */
    public function contributors(string $path, int $perPage = 30): Page
    {
        $this->featureNotSupported();
    }

    /** @return array<string, int> */
    public function languages(string $path): array
    {
        $this->featureNotSupported();
    }

    /** @return Page<Repository> */
    public function searchRepositories(string $query, int $perPage = 30): Page
    {
        $this->featureNotSupported();
    }

    public function createRepository(NewRepository $data): Repository
    {
        $this->featureNotSupported();
    }

    public function createBranch(string $path, NewBranch $data): string
    {
        $this->featureNotSupported();
    }

    public function createFile(string $path, NewFile $data): Commit
    {
        $this->featureNotSupported();
    }

    public function updateFile(string $path, UpdatedFile $data): Commit
    {
        $this->featureNotSupported();
    }

    public function createPullRequest(string $path, NewPullRequest $data): PullRequest
    {
        $this->featureNotSupported();
    }

    public function comment(string $path, NewComment $data): Comment
    {
        $this->featureNotSupported();
    }

    public function createRelease(string $path, NewRelease $data): Release
    {
        $this->featureNotSupported();
    }

    public function createTag(string $path, NewTag $data): Tag
    {
        $this->featureNotSupported();
    }

    public function createWebhook(string $path, NewWebhook $data): Webhook
    {
        $this->featureNotSupported();
    }

    public function deleteWebhook(string $path, string $id): void
    {
        $this->featureNotSupported();
    }

    /** @return list<Webhook> */
    public function listWebhooks(string $path): array
    {
        $this->featureNotSupported();
    }

    public function webhooks(string $path): Webhooks
    {
        return new Webhooks($this, $path);
    }

    /**
     * The secret to put in a clone URL for a credential.
     *
     * Shared by all three providers because getting it wrong is silent in two different
     * directions:
     *
     * - A **refreshable** credential (a GitHub App installation, an OAuth grant) has no
     *   static secret — `credentials` is null by construction, so reading it yields an
     *   EMPTY password and a URL that fails to authenticate while looking well-formed.
     *   That was the state of both the GitHub App and the GitLab OAuth paths.
     * - {@see GithubApp} is refreshable too, but its "token" is the APP's own JWT, which
     *   can mint an installation token for every installation of the app. A clone URL is
     *   handed to processes and written into `git remote`, so putting it there would be a
     *   far worse leak than the missing-credential bug above. It is refused outright.
     *
     * @throws InvalidCredentialsException when the credential cannot authenticate a clone
     */
    protected function cloneSecretFor(Credentials $credentials): string
    {
        if ($credentials instanceof GithubApp) {
            throw InvalidCredentialsException::wrongCredentialType(
                $this->name(),
                GithubAppToken::class,
                $credentials::class,
            );
        }

        if ($credentials instanceof RefreshableCredentials) {
            return $credentials->accessToken();
        }

        return (string) $credentials->credentials?->getValue();
    }

    protected function buildCloneUrl(string $baseUrl, string $user, string $secret, string $path): string
    {
        $host = str($baseUrl)->after('://')->rtrim('/')->toString();
        $scheme = str($baseUrl)->before('://')->toString();

        return "{$scheme}://{$user}:{$secret}@{$host}/{$path}.git";
    }

    protected function featureNotSupported(): never
    {
        throw FeatureNotSupportedException::for(
            feature: debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown',
            provider: $this->name(),
        );
    }

    protected function guardSupported(Feature $feature): void
    {
        if (! $this->supports($feature)) {
            throw FeatureNotSupportedException::for(
                feature: $feature->value,
                provider: $this->name(),
            );
        }
    }

    protected function guardAuthenticated(): void
    {
        if (! $this->isAuthenticated()) {
            throw InvalidCredentialsException::missing($this->name());
        }
    }

    /**
     * Require a specific credential TYPE, not merely a credential.
     *
     * GitHub authenticates `/app/**` as the app itself and everything else as one of its
     * installations, and the two are not interchangeable. Without this, the wrong
     * credential reaches GitHub and comes back as its own opaque 403 ("a JSON web token
     * could not be decoded") — a message that describes neither what was wrong nor where.
     *
     * @param  class-string<Credentials>  $required
     */
    protected function guardCredential(string $required): void
    {
        $this->guardAuthenticated();

        if (! $this->authentication instanceof $required) {
            throw InvalidCredentialsException::wrongCredentialType(
                $this->name(),
                $required,
                $this->authentication === null ? null : $this->authentication::class,
            );
        }
    }

    protected function guardAgainstInvalidCredentialsType(Credentials $credentials): void
    {
        $credentialsType = get_class($credentials);
        $supportedAuthenticationMethods = $this->authenticationMethods();

        if (! in_array($credentialsType, $supportedAuthenticationMethods)) {
            throw InvalidCredentialsException::unsupported(
                provider: $this->name(),
                credentials: $credentialsType,
                supported: $supportedAuthenticationMethods,
            );
        }
    }

    /**
     * Shared HTTP client builder for every provider request.
     */
    protected function client(): PendingRequest
    {
        /** @var array<string, mixed> $http */
        $http = config("git.providers.{$this->key()}", []);

        [$times, $backoff] = $this->retrySettings($http);

        $request = Http::withOptions($this->options($http))
            ->timeout(is_int($http['timeout'] ?? null) ? $http['timeout'] : 10)
            ->retry($times, $backoff, throw: false)
            ->baseUrl(is_string($http['url'] ?? null) ? $http['url'] : $this->providerName()->apiBaseUrl())
            ->acceptJson()
            ->asJson();

        return $this->applyAuthentication($request);
    }

    /**
     * Issue a concurrent pool of GET requests keyed by the caller's id.
     *
     * Each pooled request carries the same (refreshable) authentication as the
     * single-request path. Pooled GETs bypass the ETag conditional cache. Input
     * larger than `git.batch.concurrency` is chunked into sequential pools.
     *
     * @param  array<string, array{url: string, query: array<string, mixed>}>  $specs
     * @return array<string, Response|BatchError>
     */
    public function runPool(array $specs): array
    {
        if ($specs === []) {
            return [];
        }

        $concurrency = config('git.batch.concurrency');
        $concurrency = is_int($concurrency) && $concurrency > 0 ? $concurrency : 25;

        $outcomes = [];

        foreach (array_chunk($specs, $concurrency, true) as $chunk) {
            foreach ($this->resolveChunk($chunk) as $key => $outcome) {
                $outcomes[$key] = $outcome;
            }
        }

        return $outcomes;
    }

    /**
     * @param  array<string, array{url: string, query: array<string, mixed>}>  $chunk
     * @return array<string, Response|BatchError>
     */
    private function resolveChunk(array $chunk): array
    {
        // Account for every pooled request up front through the rate limiter so
        // client-side quota stays accurate. Once the window is exhausted the
        // remaining keys fail softly as rate-limited batch errors instead of
        // hammering the provider.
        $rateLimit = $this->rateLimiter($this->key());
        $allowed = [];
        $limited = [];

        foreach ($chunk as $key => $spec) {
            if ($limited !== []) {
                $limited[$key] = $spec;

                continue;
            }

            if ($rateLimit !== null && $rateLimit->tooManyAttempts()) {
                $limited[$key] = $spec;

                continue;
            }

            // Record the hit up front; the request itself is sent below without
            // the limiter so the store is never double-counted.
            $rateLimit?->handle(static fn (): null => null);
            $allowed[$key] = $spec;
        }

        $outcomes = [];

        if ($allowed !== []) {
            /** @var array<string, Response|ConnectionException> $responses */
            $responses = Http::pool(function (Pool $pool) use ($allowed): array {
                $requests = [];

                foreach ($allowed as $key => $spec) {
                    $query = $spec['query'] === [] ? '' : '?'.http_build_query($spec['query']);
                    $requests[] = $this->poolRequest($pool->as((string) $key))->get($spec['url'].$query);
                }

                return $requests;
            });

            $lastSuccessful = null;

            foreach ($responses as $key => $response) {
                $key = (string) $key;

                if ($response instanceof ConnectionException) {
                    $outcomes[$key] = new BatchError($key, null, $response->getMessage());

                    continue;
                }

                if ($response->failed()) {
                    $outcomes[$key] = new BatchError($key, $response->status(), 'request failed');

                    continue;
                }

                $outcomes[$key] = $response;
                $lastSuccessful = $response;
            }

            if ($lastSuccessful !== null) {
                $this->captureRateLimit($lastSuccessful);
            }
        }

        foreach ($limited as $key => $spec) {
            $outcomes[(string) $key] = new BatchError((string) $key, null, 'rate limited');
        }

        return $outcomes;
    }

    protected function poolRequest(PendingRequest $request): PendingRequest
    {
        /** @var array<string, mixed> $http */
        $http = config("git.providers.{$this->key()}", []);

        [$times, $backoff] = $this->retrySettings($http);

        $request = $request->withOptions($this->options($http))
            ->timeout(is_int($http['timeout'] ?? null) ? $http['timeout'] : 10)
            ->retry($times, $backoff, throw: false)
            ->baseUrl(is_string($http['url'] ?? null) ? $http['url'] : $this->providerName()->apiBaseUrl())
            ->acceptJson()
            ->asJson();

        return $this->applyAuthentication($request);
    }

    protected function applyAuthentication(PendingRequest $request): PendingRequest
    {
        $credential = $this->authentication;

        $token = $credential instanceof RefreshableCredentials
            ? $credential->accessToken()
            : $this->tokenValue();

        return $request->withToken($token);
    }

    protected function tokenValue(): string
    {
        return (string) $this->authentication?->credentials?->getValue();
    }

    /**
     * What distinguishes ONE caller's cache entries from another's.
     *
     * The conditional (ETag) cache was keyed on `tokenValue()`, which is the empty string
     * for every refreshable credential — so with `GIT_CACHE_ENABLED=true` two different
     * installations shared one entry for `/installation/repositories`, a URL that carries
     * no discriminator of its own. The only thing between that and serving one account's
     * repository list to another is GitHub never returning a 304 across accounts.
     *
     * An installation is identified by app + installation + scope, never by the minted
     * token (which rotates hourly and would evict the cache every hour for nothing).
     */
    protected function credentialIdentity(): string
    {
        $credential = $this->authentication;

        if ($credential instanceof GithubAppToken) {
            return 'app:'.$credential->appId.':'.$credential->installationId.':'.($credential->scope?->digest() ?? 'wide');
        }

        if ($credential instanceof GithubApp) {
            return 'app:'.$credential->appId;
        }

        if ($credential instanceof OauthToken) {
            // The refresh token is the stable per-user half; digested, never embedded.
            return 'oauth:'.(new Digest)->hex($credential->refreshToken);
        }

        return $this->tokenValue();
    }

    /**
     * @param  array<string, mixed>  $http
     * @return array{0: int, 1: int}
     */
    protected function retrySettings(array $http): array
    {
        $retry = $http['retry'] ?? 1;

        if (is_array($retry)) {
            return [
                is_int($retry['times'] ?? null) ? $retry['times'] : 1,
                is_int($retry['backoff'] ?? null) ? $retry['backoff'] : 0,
            ];
        }

        return [(int) $retry, 0];
    }

    /**
     * @param  array<string, mixed>  $http
     * @return array<string, mixed>
     */
    protected function options(array $http): array
    {
        return is_array($http['options'] ?? null) ? $http['options'] : [];
    }

    /**
     * Send a GET request through the shared client, with ETag conditional
     * caching, optional logging, and rate-limit header capture.
     *
     * @param  array<string, mixed>  $query
     */
    protected function get(string $url, array $query = []): Response
    {
        $cache = new ConditionalCache($this->key());
        $cacheKey = $cache->key($url.'?'.http_build_query($query), $this->credentialIdentity());
        $cached = $cache->enabled() ? $cache->get($cacheKey) : null;

        $request = $this->client();

        if ($cached !== null) {
            $request = $request->withHeaders(['If-None-Match' => $cached['etag']]);
        }

        $start = microtime(true);
        $response = $this->throttled($this->key(), fn (): Response => $request->get($url, $query));
        $this->log('GET', $url, $response->status(), $start);
        $this->captureRateLimit($response);

        if ($cached !== null && $response->status() === 304) {
            return new Response(new GuzzleResponse(200, $response->headers(), $cached['body']));
        }

        $response->throw();

        if ($cache->enabled()) {
            $etag = $response->header('ETag');

            if ($etag !== '') {
                $cache->put($cacheKey, $etag, $response->body());
            }
        }

        return $response;
    }

    /**
     * Send a write request (POST/PUT/PATCH/DELETE) through the shared client.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function send(string $method, string $url, array $payload = []): Response
    {
        $start = microtime(true);
        $response = $this->throttled(
            $this->key(),
            fn (): Response => $this->client()->send($method, $url, ['json' => $payload]),
        );
        $this->log($method, $url, $response->status(), $start);
        $this->captureRateLimit($response);

        return $response->throw();
    }

    protected function captureRateLimit(Response $response): void
    {
        $status = RateLimitStatusParser::fromResponse($response);

        if ($status !== null) {
            $this->rateLimit = $status;
        }
    }

    protected function log(string $method, string $url, int $status, float $startedAt): void
    {
        if (! config('git.logging.enabled', false)) {
            return;
        }

        $channel = config('git.logging.channel');
        $logger = is_string($channel) && $channel !== '' ? Log::channel($channel) : Log::getFacadeRoot();

        $logger->debug('git request', [
            'provider' => $this->key(),
            'method' => $method,
            'url' => $url,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }

    /**
     * Page through a list endpoint, returning a single page.
     *
     * @template T
     *
     * @param  array<string, mixed>  $query
     * @param  Closure(array<string, mixed>): T  $map
     * @param  string|null  $itemsKey  json key holding the list (null = root array)
     * @return Page<T>
     */
    protected function paginate(string $url, array $query, int $page, int $perPage, Closure $map, ?string $itemsKey = null): Page
    {
        $query = array_merge($query, $this->pageParameters($page, $perPage));

        $response = $this->get($url, $query);

        $raw = $itemsKey !== null ? $response->collect($itemsKey) : $response->collect();

        /** @var list<T> $items */
        $items = $raw->map(fn (array $item): mixed => $map($item))->values()->all();

        return new Page(
            items: $items,
            perPage: $perPage,
            page: $page,
            hasMore: $this->hasMorePages($response, count($items), $perPage),
        );
    }

    /**
     * Lazily auto-follow every page of a list endpoint.
     *
     * @template T
     *
     * @param  Closure(int): Page<T>  $fetch
     * @return LazyCollection<int, T>
     */
    protected function lazyPages(Closure $fetch): LazyCollection
    {
        return LazyCollection::make(function () use ($fetch) {
            $page = 1;

            do {
                $result = $fetch($page);

                foreach ($result->items as $item) {
                    yield $item;
                }

                $page++;
            } while ($result->hasMore);
        });
    }

    /** @return array<string, int> */
    protected function pageParameters(int $page, int $perPage): array
    {
        return ['page' => $page, 'per_page' => $perPage];
    }

    protected function hasMorePages(Response $response, int $count, int $perPage): bool
    {
        return $count >= $perPage;
    }
}
