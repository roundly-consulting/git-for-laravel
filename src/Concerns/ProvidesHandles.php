<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Concerns;

use RoundlyConsulting\Git\Dto\Repository;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Handles\InstallationsHandle;
use RoundlyConsulting\Git\Handles\RepositoryHandle;

/**
 * The `repo()` / `installations()` handles, shared by every driver and by the fake so
 * both hand out the same thin wrappers over their own `Provider` methods.
 */
trait ProvidesHandles
{
    /**
     * A handle on one repository — by path (`'acme/app'`) or by a `Repository` this
     * provider returned.
     *
     * @throws OutOfScopeException when the repository belongs to another provider, or
     *                             the path could step outside it
     */
    public function repo(string|Repository $repository): RepositoryHandle
    {
        if ($repository instanceof Repository) {
            if ($repository->provider !== $this->providerName()) {
                throw OutOfScopeException::foreignRepository($repository->path, $repository->provider, $this->providerName());
            }

            $repository = $repository->path;
        }

        return new RepositoryHandle($this, $repository);
    }

    public function installations(): InstallationsHandle
    {
        return new InstallationsHandle($this);
    }
}
