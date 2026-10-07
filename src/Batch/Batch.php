<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Batch;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
     *
     * @throws InvalidArgumentException when a reference is not "path#number" (number ≥ 1)
     */
    public function pullRequest(array $references): BatchResult
    {
        $this->guardPullRequestReferences($references);

        $specs = [];

        foreach ($references as $id => $reference) {
            $specs[$id] = [
                'url' => $this->provider->pullRequestUrl(Str::beforeLast($reference, '#'), (int) Str::afterLast($reference, '#')),
                'query' => [],
            ];
        }

        return $this->resolve($specs, fn (Response $response): PullRequest => $this->provider->mapResource()->pullRequest($response->json()));
    }

    /**
     * Every reference must be `path#number` with a number of 1 or more — refused before
     * anything is sent, rather than failing on a missing `#` or requesting `/pulls/0`.
     * Shared with the fake batch, so a host test refuses what production refuses.
     *
     * @param  array<string, string>  $references
     *
     * @throws InvalidArgumentException
     */
    protected function guardPullRequestReferences(array $references): void
    {
        foreach ($references as $id => $reference) {
            if (preg_match('/^[^#]+#[1-9][0-9]*\z/', $reference) !== 1) {
                throw new InvalidArgumentException(
                    "The pull request reference [{$reference}] for [{$id}] is not \"path#number\" with a number of 1 or more."
                );
            }
        }
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
