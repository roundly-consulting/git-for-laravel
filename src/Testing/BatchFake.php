<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Testing;

use RoundlyConsulting\Git\Batch\Batch;
use RoundlyConsulting\Git\Batch\BatchResult;
use RoundlyConsulting\Git\Enums\ProviderName;

/**
 * In-memory {@see Batch} double that records pooled calls and returns seeded
 * results without issuing any HTTP.
 */
final class BatchFake extends Batch
{
    /** @param array<string, BatchResult<mixed>> $seeded */
    public function __construct(
        private readonly ProviderName $name,
        private readonly GitFake $git,
        private readonly array $seeded = [],
    ) {}

    /**
     * @param  list<string>  $paths
     * @return BatchResult<mixed>
     */
    public function repositories(array $paths): BatchResult
    {
        return $this->dispatch('repositories', $paths);
    }

    /**
     * @param  list<string>  $paths
     * @return BatchResult<mixed>
     */
    public function languages(array $paths): BatchResult
    {
        return $this->dispatch('languages', $paths);
    }

    /**
     * @param  list<string>  $filePaths
     * @return BatchResult<mixed>
     */
    public function contents(string $path, array $filePaths, ?string $ref = null): BatchResult
    {
        return $this->dispatch('contents', [$path, $filePaths, $ref]);
    }

    /**
     * @param  array<string, string>  $references
     * @return BatchResult<mixed>
     */
    public function pullRequest(array $references): BatchResult
    {
        return $this->dispatch('pullRequest', $references);
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @return BatchResult<mixed>
     */
    private function dispatch(string $method, array $arguments): BatchResult
    {
        $this->git->record($this->name, new RecordedCall("batch.{$method}", array_values($arguments)));

        return $this->seeded[$method] ?? new BatchResult([], []);
    }
}
