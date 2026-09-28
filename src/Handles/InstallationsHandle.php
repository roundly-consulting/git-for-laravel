<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Handles;

use RoundlyConsulting\Git\Dto\Installation;
use RoundlyConsulting\Git\Dto\Page;
use RoundlyConsulting\Git\Exceptions\OutOfScopeException;
use RoundlyConsulting\Git\Interfaces\Provider;

/**
 * A provider's app installations — `Git::githubApp()->installations()->find($id)`.
 *
 * Every lookup here runs AS THE APP, so drive it from `Git::githubApp()` (the app's own
 * JWT); an installation token is refused by the provider with a message saying so. A
 * provider without app installations answers `FeatureNotSupportedException`.
 */
final readonly class InstallationsHandle
{
    public function __construct(
        private Provider $provider,
    ) {}

    /** @return Page<Installation> */
    public function all(int $perPage = 30): Page
    {
        return $this->provider->listInstallations($perPage);
    }

    /**
     * Look an installation up by id — how a service verifies an id somebody handed it
     * (say, on the install redirect) before binding anything to it.
     *
     * @throws OutOfScopeException when the id is not numeric
     */
    public function find(string $id): Installation
    {
        return $this->provider->installation(PathGuard::numeric('installation id', $id));
    }

    /** This app's installation on an organization. */
    public function forOrganization(string $organization): Installation
    {
        return $this->provider->organizationInstallation(PathGuard::segment('organization', $organization));
    }

    /** This app's installation on a user account. */
    public function forUser(string $login): Installation
    {
        return $this->provider->userInstallation(PathGuard::segment('login', $login));
    }

    /** Where to send a human to install the app, carrying `state` back to your Setup URL. */
    public function installUrl(?string $state = null): string
    {
        return $this->provider->installUrl($state);
    }
}
