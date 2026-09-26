# Changelog

All notable changes to `git-for-laravel` will be documented in this file.

## Unreleased

### Fixed

- An unauthenticated provider no longer sends an empty `Authorization: Bearer` header, which
  GitHub rejected with `401 Bad credentials` — anonymous reads of public repositories work on
  GitHub, GitLab and Bitbucket, single requests and batch pools alike.
- A `401` now throws `InvalidCredentialsException` (`missing()` with no credential, the new
  `rejected()` with one) instead of a raw `RequestException`; `403`/`404`/`429` stay the
  documented `RequestException`.
- The retry policy now matches its documentation: only reads are retried, and only on a dropped
  connection, a `429` or a `5xx`. A `4xx` (or a conditional `304`) is no longer retried, and a
  write is never retried, so a `5xx` on a POST can no longer create a duplicate pull request,
  comment or release.
