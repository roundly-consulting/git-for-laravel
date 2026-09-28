<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Enums;

use RoundlyConsulting\Enums\Helpers;
use RoundlyConsulting\Git\Dto\Input\NewComment;

/**
 * What a {@see NewComment} number refers to.
 *
 * GitHub numbers issues and pull requests in one sequence and comments on both through
 * the same endpoint, so it needs no target. GitLab numbers issues and merge requests
 * separately — issue #3 and merge request !3 are different objects — so a GitLab comment
 * must name one; Bitbucket comments only on pull requests here.
 */
enum CommentTarget: string
{
    use Helpers;

    case Issue = 'issue';

    /** A pull request — a merge request on GitLab. */
    case PullRequest = 'pull_request';
}
