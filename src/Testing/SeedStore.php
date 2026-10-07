<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Testing;

use RoundlyConsulting\Git\Batch\BatchResult;

/**
 * The seeds of one fake provider, in an object so every per-call copy of the driver
 * ({@see ProviderFake::fresh()}) reads and writes the same ones.
 *
 * @internal the fake drivers' shared state.
 */
final class SeedStore
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, BatchResult<mixed>> */
    public array $batches = [];
}
