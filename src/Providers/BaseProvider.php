<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Providers;

use Closure;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\LazyCollection;
use RoundlyConsulting\Git\Concerns\InteractsWithRateLimits;
use RoundlyConsulting\Git\Dto\Comment;
use RoundlyConsulting\Git\Dto\Commit;
use RoundlyConsulting\Git\Dto\Comparison;
use RoundlyConsulting\Git\Dto\Contributor;
use RoundlyConsulting\Git\Dto\Credentials\Credentials;
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
        $this->enforceRateLimit($this->key(), $this->rateLimitFromConfig($this->key()));

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

    protected function applyAuthentication(PendingRequest $request): PendingRequest
    {
        return $request->withToken($this->tokenValue());
    }

    protected function tokenValue(): string
    {
        return (string) $this->authentication?->credentials?->getValue();
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
        $cacheKey = $cache->key($url.'?'.http_build_query($query), $this->tokenValue());
        $cached = $cache->enabled() ? $cache->get($cacheKey) : null;

        $request = $this->client();

        if ($cached !== null) {
            $request = $request->withHeaders(['If-None-Match' => $cached['etag']]);
        }

        $start = microtime(true);
        $response = $request->get($url, $query);
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
        $response = $this->client()->send($method, $url, ['json' => $payload]);
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
