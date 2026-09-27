# Changelog

All notable changes to `git-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- One Laravel-native API for GitHub, GitLab and Bitbucket through the `Registry` facade, with
  anonymous access to public repositories or token authentication from config.
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
  `PushReceived`, plus idempotent webhook auto-registration.
- Authenticated clone URLs (`cloneUrlForRepository()`) and feature detection with `supports()`.
- Read retries with backoff, ETag caching, and client-side rate limiting that honours the
  provider's `Retry-After`.
- Artisan commands `git:repos`, `git:commits`, `git:rate-limit` and `git:webhook`.
- `Registry::fake()`, a full test double with chainable seeders and assertions such as
  `assertSent()` and `assertRepositoryCreated()`.
