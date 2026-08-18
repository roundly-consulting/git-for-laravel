<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Dto\Input;

use InvalidArgumentException;

/**
 * A repository to create.
 *
 * The fields after `description` are appended rather than woven in so positional callers
 * written against the three-argument version keep working.
 */
final readonly class NewRepository
{
    /**
     * @param  string  $name  the repository name, without an owner
     * @param  ?string  $owner  the organization login to create under; null creates under the authenticated account
     * @param  ?string  $template  an "owner/repo" template selector to generate from
     * @param  bool  $autoInit  create an initial commit, so the repository is clonable straight away
     * @param  ?string  $defaultBranch  the name that initial commit's branch should carry
     *
     * @throws InvalidArgumentException when the name is empty, the template selector is
     *                                  malformed, or a default branch is asked for on a
     *                                  repository that would have no commit to put it on
     */
    public function __construct(
        public string $name,
        public bool $private = false,
        public ?string $description = null,
        public ?string $owner = null,
        public ?string $template = null,
        public bool $autoInit = false,
        public ?string $defaultBranch = null,
    ) {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Repository name must not be empty.');
        }

        // A selector that is not exactly "owner/repo" would be interpolated straight into
        // `/repos/{template}/generate`. "acme" produces `/repos/acme/generate` — a URL that
        // answers 404, i.e. reported as "the template does not exist" rather than "that was
        // never a template selector". Anything with a further slash escapes the segment
        // entirely and posts to some other endpoint.
        if ($template !== null) {
            $parts = explode('/', $template);

            if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
                throw new InvalidArgumentException(
                    "Repository template [{$template}] must be a \"owner/repo\" selector."
                );
            }
        }

        // A default branch only names something once there is a commit to name. Without
        // one, providers either drop the field (GitHub) or refuse it (GitLab documents
        // `default_branch` as requiring `initialize_with_readme`) — and a dropped field is
        // the failure this DTO exists to prevent: the caller believes it asked for
        // `develop` and clones `main`.
        if ($defaultBranch !== null && ! $autoInit && $template === null) {
            throw new InvalidArgumentException(
                'A default branch requires an initial commit — set autoInit or template.'
            );
        }
    }
}
