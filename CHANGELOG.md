# Changelog

All notable changes to `git-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Fixed

- GitHub `issues()` no longer lists pull requests as issues. GitHub's issues endpoint returns
  both; the pull requests are now dropped, and `hasMore` still follows the `Link` header.
- GitHub `createBranch()` now accepts a tag or a commit sha as `NewBranch::$fromRef`, as GitLab
  does. It used to read `/git/refs/heads/{fromRef}`, which is a 404 for a tag or a sha and
  crashed when the name only prefixed a branch.
- `listWebhooks()` and `repo(...)->webhooks()->all()` now read every page of hooks on all three
  forges, not only the first. A hook beyond page one was missed, so `register()` created a
  duplicate and `deleteByUrl()` / `registered()` did not see it.
- The webhook route now reads a GitHub delivery sent with the `application/x-www-form-urlencoded`
  content type (GitHub's default for hooks added in its UI). Its events used to carry an empty
  payload; the `payload` field is now decoded from the signed body.
- `webhooks()->register()` with no `$url` (the package's own route) now throws
  `InvalidArgumentException` naming `git.providers.<provider>.webhook_secret` when no secret is
  configured, or when the `$secret` passed differs from it, and sends nothing. Such a hook used to
  be created, and the route answered every delivery with 403. An explicit `$url` is unchanged.
- A `GithubAppToken` built without an `apiBaseUrl` now mints its installation token at the
  configured `git.providers.github.url` (GitHub Enterprise), not at api.github.com. The mint
  failed there as a rejected credential.

## 1.0.0 - 2026-10-03

Initial public release.

### Added

- One Laravel-native API for GitHub, GitLab and Bitbucket through the `Git` facade (or an
  injected `GitManager`), with anonymous access to public repositories or token authentication
  from config.
- Scoped handles: `Git::github()->repo('acme/app')` for everything about one repository,
  `->pullRequest(12)` for `merge()`, `approve()`, `review()`, `reviews()`, `close()` and
  `comment()`, and `Git::githubApp()->installations()` for `find()`, `all()`,
  `forOrganization()`, `forUser()` and `installUrl()`. Handles refuse paths and identifiers that
  would step outside their scope, and repositories of another provider (`OutOfScopeException`).
- `Git::credentials($provider)` returns the configured credential (app installation, else
  token) and `Git::verifyWebhook($provider, $request)` verifies an inbound webhook for a
  host-owned route.
- Read endpoints returning typed, provider-agnostic data objects: repositories, branches, commits,
  pull requests, issues, tags, releases, file contents, diffs, contributors and languages — each
  with a `raw()` escape hatch.
- Paginated lists that never truncate silently, lazy auto-pagination, a fluent commit query and
  `batch()` for concurrent reads in one round trip.
- Write operations with typed input: create repositories (including from a template), branches,
  files, pull requests, comments, releases, tags and webhooks.
- Pull-request workflows: close, approve and merge (guarded by the head commit `sha`), and whole
  reviews with inline comments in one call via `reviewPullRequest()`.
- Self-refreshing GitHub App installation tokens and OAuth tokens, repository-scoped installation
  tokens and an `OauthTokenRefreshed` event for persisting rotated refresh tokens.
- Signed webhook receiving (GitHub, GitLab, Bitbucket) dispatched as typed events such as
  `PushReceived`, plus idempotent webhook auto-registration via `repo(...)->webhooks()`.
- Authenticated clone URLs (`repo(...)->cloneUrl()`) and feature detection with `supports()`.
- Read retries with backoff, ETag caching, and client-side rate limiting that honours the
  provider's `Retry-After`.
- Artisan commands `git:repos`, `git:commits`, `git:rate-limit` and `git:webhook`.
- `Git::fake()`, a full test double (also served to an injected `GitManager`) that records
  every call — flat or through a handle — with chainable seeders and `assertSent()` (optionally
  matching arguments), `assertSentTimes()`, `assertNotSent()`, `assertNothingSent()`,
  `assertBatched()`, `assertNotBatched()`, `assertRepositoryCreated()`,
  `assertNoRepositoryCreated()` and `recorded()`.
