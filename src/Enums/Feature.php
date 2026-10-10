<?php

declare(strict_types=1);

namespace RoundlyConsulting\Git\Enums;

use RoundlyConsulting\Enums\Helpers;
use RoundlyConsulting\Git\Dto\FeatureInfo;

enum Feature: string
{
    use Helpers;

    case ListRepositories = 'repositories';
    case FindRepository = 'repository';
    case ListCommits = 'commits';
    case FindCommit = 'commit';
    case ListRepositoryBranches = 'branches';
    case FindBranch = 'branch';
    case ListPullRequests = 'pull_requests';
    case FindPullRequest = 'pull_request';
    case ListIssues = 'issues';
    case FindIssue = 'issue';
    case ListTags = 'tags';
    case ListReleases = 'releases';
    case FindRelease = 'release';
    case FileContents = 'contents';
    case Compare = 'compare';
    case ListContributors = 'contributors';
    case Languages = 'languages';
    case SearchRepositories = 'search_repositories';
    case CreateRepository = 'create_repository';
    case GenerateFromTemplate = 'generate_from_template';
    case CreateBranch = 'create_branch';
    case CreateFile = 'create_file';
    case UpdateFile = 'update_file';
    case CreatePullRequest = 'create_pull_request';
    case ClosePullRequest = 'close_pull_request';
    case ApprovePullRequest = 'approve_pull_request';
    case ReviewPullRequest = 'review_pull_request';
    case ListPullRequestReviews = 'pull_request_reviews';
    case MergePullRequest = 'merge_pull_request';
    case CreateComment = 'create_comment';
    case CreateRelease = 'create_release';
    case CreateTag = 'create_tag';
    case CreateWebhook = 'create_webhook';
    case DeleteWebhook = 'delete_webhook';
    case ListWebhooks = 'list_webhooks';
    case FindInstallation = 'installation';
    case ListInstallations = 'installations';
    case ListInstallationRepositories = 'installation_repositories';
    case RepositoryActivity = 'activity';

    public function description(): string
    {
        return match ($this) {
            self::ListRepositories => 'List of all repositories accessible by credentials.',
            self::FindRepository => 'Get details of specific repository by name, accessible by credentials.',
            self::ListCommits => 'List of all branch commits accessible by credentials.',
            self::FindCommit => 'Get details of specific commit by sha hash, accessible by credentials.',
            self::ListRepositoryBranches => 'List of all repository branches.',
            self::FindBranch => 'Get the head commit of one branch, by its exact name.',
            self::ListPullRequests => 'List pull/merge requests for a repository.',
            self::FindPullRequest => 'Get a single pull/merge request by number.',
            self::ListIssues => 'List issues for a repository.',
            self::FindIssue => 'Get a single issue by number.',
            self::ListTags => 'List tags for a repository.',
            self::ListReleases => 'List releases for a repository.',
            self::FindRelease => 'Get a single release by tag or id.',
            self::FileContents => 'Fetch the decoded contents of a file.',
            self::Compare => 'Compare two refs and list changed files.',
            self::ListContributors => 'List contributors for a repository.',
            self::Languages => 'List the languages used in a repository.',
            self::SearchRepositories => 'Search repositories by query.',
            self::CreateRepository => 'Create a new repository.',
            self::GenerateFromTemplate => 'Create a new repository from a template repository, with its initial commit already in place.',
            self::CreateBranch => 'Create a branch from a base ref.',
            self::CreateFile => 'Create a new file in a repository.',
            self::UpdateFile => 'Update an existing file in a repository.',
            self::CreatePullRequest => 'Open a new pull/merge request.',
            self::ClosePullRequest => 'Close a pull/merge request without merging it.',
            self::ApprovePullRequest => 'Approve a pull/merge request as the authenticated account.',
            self::ReviewPullRequest => 'Publish a review on a pull/merge request, with inline comments.',
            self::ListPullRequestReviews => 'Read the reviews left on a pull/merge request, with their inline comments.',
            self::MergePullRequest => 'Merge a pull/merge request.',
            self::CreateComment => 'Add a comment to a pull request, merge request, or issue.',
            self::CreateRelease => 'Create a new release.',
            self::CreateTag => 'Create a new tag.',
            self::CreateWebhook => 'Register a repository webhook.',
            self::DeleteWebhook => 'Remove a repository webhook.',
            self::ListWebhooks => 'List the webhooks registered on a repository.',
            self::FindInstallation => 'Get a single app installation (authenticated as the app itself).',
            self::ListInstallations => 'List every account this app is installed on (authenticated as the app itself).',
            self::ListInstallationRepositories => 'List the repositories one installation can reach.',
            self::RepositoryActivity => 'List the pushes, force pushes, branch creations and deletions, and merges on a repository, newest first.',
        };
    }

    public function info(bool $supported = true): FeatureInfo
    {
        return new FeatureInfo(id: $this, description: $this->description(), supported: $supported);
    }
}
