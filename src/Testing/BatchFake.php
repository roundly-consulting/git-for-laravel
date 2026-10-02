<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Testing;

use RoundlyConsulting\Git\Batch\Batch;
use RoundlyConsulting\Git\Batch\BatchResult;
use RoundlyConsulting\Git\Enums\Feature;

/**
 * In-memory {@see Batch} double that records pooled calls and returns seeded
 * results without issuing any HTTP.
 *
 * Each call first passes the faked driver's feature matrix, as the real batch does when it
 * builds a request the driver cannot (Bitbucket has no languages or file-contents URL).
 */
final class BatchFake extends Batch
{
    /** @param array<string, BatchResult<mixed>> $seeded */
    public function __construct(
        private readonly ProviderFake $provider,
        private readonly GitFake $git,
        private readonly array $seeded = [],
    ) {}

    /**
     * @param  list<string>  $paths
     * @return BatchResult<mixed>
     */
    public function repositories(array $paths): BatchResult
    {
        $this->provider->ensureSupported(Feature::FindRepository);

        return $this->dispatch('repositories', $paths);
    }

    /**
     * @param  list<string>  $paths
     * @return BatchResult<mixed>
     */
    public function languages(array $paths): BatchResult
    {
        $this->provider->ensureSupported(Feature::Languages);

        return $this->dispatch('languages', $paths);
    }

    /**
     * @param  list<string>  $filePaths
     * @return BatchResult<mixed>
     */
    public function contents(string $path, array $filePaths, ?string $ref = null): BatchResult
    {
        $this->provider->ensureSupported(Feature::FileContents);

        return $this->dispatch('contents', [$path, $filePaths, $ref]);
    }

    /**
     * @param  array<string, string>  $references
     * @return BatchResult<mixed>
     */
    public function pullRequest(array $references): BatchResult
    {
        $this->provider->ensureSupported(Feature::FindPullRequest);

        return $this->dispatch('pullRequest', $references);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @return BatchResult<mixed>
     */
    private function dispatch(string $method, array $arguments): BatchResult
    {
        $this->git->record($this->provider->providerName(), new RecordedCall("batch.{$method}", array_values($arguments)));

        return $this->seeded[$method] ?? new BatchResult([], []);
    }
}
