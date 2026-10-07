<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Batch;

use Illuminate\Http\Client\Response;
use RoundlyConsulting\Git\Dto\FileContent;
use RoundlyConsulting\Git\Dto\PullRequest;
use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Providers\BaseProvider;

/**
 * Fluent concurrent-fetch surface over a single provider. Each call fans the
 * inputs out through one (or more, when chunked) `Http::pool()` round trip and
 * returns a keyed {@see BatchResult}.
 *
 * Typed to `BaseProvider`, not the `Provider` contract: it drives the driver's
 * `@internal` URL builders and mappers, which are deliberately not part of the contract.
 * Host code gets one from `Git::github()->batch()`.
 */
class Batch
{
    public function __construct(private readonly BaseProvider $provider) {}

    /**
     * @param  list<string>  $paths
     * @return BatchResult<Repository>
     */
    public function repositories(array $paths): BatchResult
    {
        $specs = [];

        foreach ($paths as $path) {
            $specs[$path] = ['url' => $this->provider->repositoryUrl($path), 'query' => []];
        }

        return $this->resolve($specs, fn (Response $response): Repository => $this->provider->mapResource()->repository($response->json()));
    }

    /**
     * @param  list<string>  $paths
     * @return BatchResult<array<string, int>>
     */
    public function languages(array $paths): BatchResult
    {
        $specs = [];

        foreach ($paths as $path) {
            $specs[$path] = ['url' => $this->provider->languagesUrl($path), 'query' => []];
        }

        return $this->resolve($specs, fn (Response $response): array => $this->provider->normalizeLanguages($response->json()));
    }

    /**
     * @param  list<string>  $filePaths
     * @return BatchResult<FileContent>
     */
    public function contents(string $path, array $filePaths, ?string $ref = null): BatchResult
    {
        $specs = [];

        foreach ($filePaths as $filePath) {
            [$url, $query] = $this->provider->contentsRequest($path, $filePath, $ref);
            $specs[$filePath] = ['url' => $url, 'query' => $query];
        }

        return $this->resolve($specs, fn (Response $response, string $filePath): FileContent => $this->provider->fileContent($path, $filePath, $response->json()));
    }

    /**
     * @param  array<string, string>  $references  caller id => "path#number"
     * @return BatchResult<PullRequest>
     */
    public function pullRequest(array $references): BatchResult
    {
        $specs = [];

        foreach ($references as $id => $reference) {
            [$path, $number] = explode('#', $reference, 2);
            $specs[$id] = ['url' => $this->provider->pullRequestUrl($path, (int) $number), 'query' => []];
        }

        return $this->resolve($specs, fn (Response $response): PullRequest => $this->provider->mapResource()->pullRequest($response->json()));
    }

    /**
     * @template T
     *
     * @param  array<string, array{url: string, query: array<string, mixed>}>  $specs
     * @param  callable(Response, string): T  $map  the response and the caller's key for it
     * @return BatchResult<T>
     */
    private function resolve(array $specs, callable $map): BatchResult
    {
        $outcomes = $this->provider->runPool($specs);

        $results = [];
        $errors = [];

        foreach ($outcomes as $key => $outcome) {
            if ($outcome instanceof BatchError) {
                $errors[$key] = $outcome;

                continue;
            }

            $results[$key] = $map($outcome, (string) $key);
        }

        return new BatchResult($results, $errors);
    }
}
