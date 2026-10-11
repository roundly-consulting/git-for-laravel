# Changelog

All notable changes to `git-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

## 1.2.1 - 2026-10-11

### Changed

- Maintenance: requires the latest roundly packages — crypto `^1.1.2`, enums `^1.1.0`,
  http-client-rate-limits `^1.1.0`, package-toolkit `^1.3.0`; dev: testing `^1.2.1`.

## 1.2.0 - 2026-10-10

### Added

- `Comparison` carries GitHub's own verdict and the commits: `status` (`Enums\ComparisonStatus`:
  ahead, behind, identical, diverged), `totalCommits` and `commits` (`list<Commit>`, oldest
  first), and `raw()` now holds the whole response. Unpaged, GitHub sends the newest 250 commits
  and counts all of them, so `totalCommits > count($commits)` means the list was cut. GitLab
  fills `commits` and `totalCommits` but leaves `status` `null` (it cannot tell diverged from
  ahead), and a status GitHub has not documented is `null` too. The new constructor parameters
  come after `raw`, so positional `new Comparison(...)` calls keep working.
- `repo(...)->branch($name)` returns one branch head (`Dto\Branch`: `name`, `sha`) by its exact
  name, on GitHub, GitLab and Bitbucket (`Feature::FindBranch`). A tag, a sha or a prefix of a
  branch name is the usual `404` `RequestException`, never another commit; GitHub reads
  `/git/ref/heads/{branch}`, so a renamed branch is not followed to another head. An answer that
  is not exactly that branch's head throws `UnexpectedValueException`. The fake answers
  `seedBranch()`, by name.
- `repo(...)->activity(?$ref, $perPage, ?$cursor)` reads GitHub's repository activity feed,
  newest first: a `Page<Dto\Activity>` (`type` as `Enums\ActivityType` — push, force push,
  branch creation and deletion, pull request and merge queue merges — `ref`, `before`, `after`,
  `actor`, `occurredAt`), optionally for one ref (`main` or `refs/heads/main`). It is
  cursor-paged: hand the page's `nextCursor` back as `$cursor`. An all-zero `before` / `after`
  (a creation or deletion) is `null`, a type GitHub adds later is `ActivityType::Unknown`, and a
  `403` / `404` stays a `RequestException`. GitHub only (`Feature::RepositoryActivity`); the
  fake pages `seedActivity()` filtered by ref.
- GitHub Actions types: `Dto\WorkflowRun` (with `isCompleted()`, `isActive()`, `succeeded()`,
  `wasCancelled()`), `Dto\WorkflowJob` (with its `steps` as `Dto\JobStep`, `succeeded()` and
  `step($name)`), `Dto\DispatchedWorkflow`, `Dto\Input\NewWorkflowDispatch`, the
  `Enums\WorkflowStatus` and `Enums\WorkflowConclusion` enums, and the chainable
  `Query\WorkflowRunQuery` (`branch()`, `event()`, `status()`, `actor()`, `headSha()`,
  `createdAfter()`, `createdBefore()`, `excludePullRequests()`) and `Query\WorkflowJobQuery`
  (`allAttempts()`, `attempt($n)`). A status or conclusion GitHub adds later reads as `Unknown`,
  which never counts as completed or successful, and a run or job payload without an id throws
  `InvalidArgumentException` naming the field instead of making one up.
- `Page::$total`: how many items the whole list holds, when the forge says (`null` otherwise).
- `repo(...)->actions()` drives GitHub Actions (`Handles\ActionsHandle`): `dispatch($workflow,
  $ref, $inputs)` starts a workflow and returns a `DispatchedWorkflow` whose `runId` names the
  run GitHub started (github.com and GHE.com; GitHub Enterprise Server answers without it, so
  find the run with `runs()` from `dispatchedAt`); `runs(?$workflow)` queries runs newest first
  with `total` from GitHub's count; `run($id)`; `jobs($runId)` (latest attempt by default);
  `cancel($runId)` is `true` when GitHub accepted it and `false` when the run had already
  finished (`409`). A dispatch is never retried, every workflow and run id is checked before a
  request, and dispatch and cancel need a credential. GitHub only (`Feature::DispatchWorkflow`,
  `ListWorkflowRuns`, `FindWorkflowRun`, `ListWorkflowJobs`, `CancelWorkflowRun`).
- `Git::fake()` drives GitHub Actions honestly: `dispatch()` creates the queued run it reports
  (the next numeric id), `runs()` applies the workflow and every filter newest first, `jobs()`
  keeps the latest attempt unless asked for all or one, and `cancel()` is `false` for a run that
  already completed. Seed with `seedWorkflowRuns()`, `seedWorkflowRun()` (an upsert by id — the
  next poll's state), `seedWorkflowJobs()` and `seedDispatchWithoutRunDetails()` (GitHub
  Enterprise Server's answer without a run id). New assertions on the fake and the facade:
  `assertWorkflowDispatched($workflow, ?$ref, ?$inputs, ?$repository)` (inputs exact),
  `assertNoWorkflowDispatched()`, `assertWorkflowRunCancelled($runId, ?$repository)` and
  `assertNoWorkflowRunCancelled()`.
- `PullRequest::$headSha` and `$headRepository` (`owner/name` of the head branch's repository —
  a fork's for a pull request from a fork), and `Repository::$private`, `$language` and
  `$webUrl`, all nullable and appended after `raw`. They reach webhook events too. Per forge:
  Bitbucket's `headSha` is its 12-character short hash; GitLab has no `headRepository` (only a
  project id) and no `language`, and counts `internal` as private (from `visibility`, or a hook's
  `visibility_level`); a deleted GitHub fork has no `headRepository`.

### Changed

- `Interfaces\Provider` gained seven methods: `branch()`, `activity()`, `dispatchWorkflow()`,
  `workflowRuns()`, `workflowRun()`, `workflowJobs()` and `cancelWorkflowRun()`. A class
  implementing `Provider` directly must add them; a driver extending `BaseProvider` (or one of
  the three drivers) changes nothing.
- `toArray()` / JSON gain keys: `Page` `total`; `Comparison` `status`, `totalCommits` and
  `commits`; `PullRequest` `headSha` and `headRepository`; `Repository` `private`, `language` and
  `webUrl`. The fake's default comparison (nothing seeded) is now `status: identical`,
  `totalCommits: 0`, and the repository `createRepository()` synthesizes carries `private`.
- A write with no payload (cancelling a workflow run, deleting a webhook) now sends no request
  body instead of a JSON `[]`, which GitHub refuses where it expects an object or nothing.
- GitLab compare `commits` are now mapped through the commit mapper, so a compare answer whose
  commits lack GitLab's usual commit fields (`message`, `author_name`, `author_email`) now fails
  to map instead of being only counted.

## 1.1.4 - 2026-10-07

### Fixed

- GitLab `WebhookEvent::pullRequest()` maps the merge request a Note Hook on a merge request and a
  merge request pipeline's Pipeline Hook carry (their top-level `merge_request`), as GitHub review
  events and Bitbucket pull request comment events already return the pull request; since 1.1.3 it
  returned `null` for them. A Note Hook's merge request has no `url`, and a pipeline's no `body`,
  `author` or `draft` flag; its `createdAt` is the time of mapping, as the hook carries none.
  Issue, issue/commit/snippet comment, branch pipeline and push hooks still give `null`.

## 1.1.3 - 2026-10-07

### Fixed

- GitLab `WebhookEvent::pullRequest()` returns `null` for every hook but a Merge Request Hook
  (`object_kind: merge_request`), as GitHub and Bitbucket return `null` when there is no pull
  request. It used to read any hook's `object_attributes` as a merge request: an Issue Hook gave
  the issue back as a `PullRequest`, and a Pipeline or Note Hook threw `Undefined array key`.
- GitLab's `Tag Push Hook` counts as a push: `WebhookEvent::isPush()` is `true` and the webhook
  route dispatches `PushReceived` for it, with `ref()` the tag's `refs/tags/<tag>`, as GitHub's
  `push` and Bitbucket's `repo:push` already do for tag pushes.

## 1.1.2 - 2026-10-07

### Fixed

- GitLab `WebhookEvent::repository()` no longer makes up an owner id of `"0"` on real hook
  payloads, which carry no `namespace_id`: `owner->id` is `''` unless the hook has one. Its
  `lastActivityAt` is now the hook's own event time (the newest commit of a push,
  `object_attributes.updated_at`, a pipeline's or job's latest time, a deployment's
  `status_changed_at`, a created release's `created_at`) instead of the time of mapping, which
  stays only for a hook that carries no time. `createdAt` is still the time of mapping, as hooks
  carry none; fetch the repository through the API for the full record.
- `Git::fake()`'s `installUrl()` URL-encodes the app slug, as the real GitHub driver does, so a
  slug with a reserved character no longer gives a URL production never builds.

## 1.1.1 - 2026-10-07

### Fixed

- Bitbucket `pullRequests()` caps `$perPage` at 50, the most Bitbucket's pull request endpoint
  takes: 51–100 used to answer `400 Invalid pagelen`. Bitbucket's other lists keep their own
  limits, and `Git::fake()` pages a Bitbucket pull request list the same way.
- GitLab `WebhookEvent::repository()` no longer throws a `TypeError` on real hook payloads,
  whose `project.namespace` is the namespace's name (a string) rather than the REST object. The
  owner is read from `path_with_namespace`; the name stays on `owner->raw['name']`.
- GitHub `issue($number)` with a pull request's number throws the same `404`
  `RequestException` as a missing issue, instead of returning the pull request as an `Issue`
  (GitHub's `/issues/{number}` serves both). The issue list already left pull requests out.
- `Git::fake()`'s `installUrl()` throws `InvalidCredentialsException::missingAppConfig()` naming
  `git.providers.github.app.slug` when no app slug is configured, as the real driver does,
  instead of falling back to a `fake-app` slug. A host test that builds an install URL on the
  fake now configures the slug.
- A fake clone URL for a credential with an empty secret keeps its `:` (`https://user:@…`),
  as the real drivers build it, instead of dropping it.
- `batch()->contents()` with a directory path reports a `BatchError` for that path only (no
  status, the `notAFile` message) instead of failing the whole batch with an
  `OutOfScopeException`; the other files are still returned.
- `php artisan git:webhook` prints a refused registration — no URL to derive with the route
  disabled, a secret the package route cannot verify, a hook at the URL with other events — as
  a one-line error and exits 1, instead of showing the `InvalidArgumentException`.

## 1.1.0 - 2026-10-07

### Changed

- Client-side rate limiting keeps one budget per credential: the limiter key is now
  `git:<provider>:<owner>:<digest>` — a digest of the token, OAuth grant or GitHub App
  installation (never the secret itself), or `anon` without a credential. One installation's
  exhausted quota (and the adaptive `Retry-After` penalty that comes with it) no longer stalls
  every other credential. Scoped mints of one installation share its budget, as GitHub counts
  them. A class using `Concerns\InteractsWithRateLimits` outside `BaseProvider` must now
  implement `rateLimitIdentity()`.
- `Git::fake()` now refuses the inputs the real drivers refuse, by running the drivers' own
  checks: Bitbucket commit filters by author or date, a GitLab comment with no `target`, a
  Bitbucket comment on an issue, Bitbucket `createRepository()` with `autoInit` or a
  `defaultBranch`, GitLab `createRepository()` with a non-numeric `owner`, and a GitLab webhook
  that is inactive or names an unknown event. A host test that relied on the fake accepting one
  of these now fails as production would.
- `Git::fake()` now authenticates like the real manager. Each `Git::github()` / `gitlab()` /
  `bitbucket()` / `provider()` call gets its own fake driver (sharing the provider's seeds and
  records) with the credential passed, else the configured one, else none. As in production, a
  write or `listWebhooks()` with no credential throws `InvalidCredentialsException::missing()`,
  a credential type the forge does not take throws `unsupported()` (e.g. `Git::bitbucket()`
  with an `OauthToken`), `installations()` needs a `GithubApp` and `installationRepositories()`
  a `GithubAppToken` (`wrongCredentialType()`), `Git::githubApp()` with no app configured
  throws `missingAppConfig()`, and `authenticationMethods()` / `isAuthenticated()` answer as the
  real driver does. Host tests now configure fake credentials — nothing signs or sends with
  them: a token per forge (`git.providers.<provider>.token`), plus `git.providers.github.app.id`
  (numeric) and `app.private_key` (any string) for `Git::githubApp()`, and
  `app.installation_id` when `Git::github()` should act as the installation. Seed through
  `Git::fake()->fakeFor(ProviderName::…)`, which needs no credential.
- GitLab `createBranch()` now returns the new ref, `refs/heads/<name>`, as documented and as
  GitHub and the fake return it. It used to return the bare branch name.
- File paths may now contain `#` and `?`, and refs `#` (`contents('docs/C#/intro.md')`,
  `compare('main', 'fix/#123')`): every driver percent-encodes them (`%23`, `%3F`), so they
  used to be refused with `OutOfScopeException` for no reason. Repository paths and account
  names still refuse both, refs still refuse `?`, and `\` and NUL stay refused everywhere. A
  segment that would read as `.` or `..` once cut at a `?` or `#` is refused as a traversal.
- Documentation: the README no longer says its example runs unchanged on GitLab and Bitbucket;
  it names the calls each one lacks.
- Documentation: the README hero image uses an absolute URL, so it renders on Packagist and other
  sites.
- Maintenance: `composer.json` `homepage` and `support.docs` link to the package documentation.

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
- An OAuth refresh answered `400 invalid_grant` (an expired, revoked or already-used refresh
  token, as GitLab returns it) now throws `InvalidCredentialsException::refreshTokenRejected()` —
  the package's "reconnect required" signal — instead of a retryable `RequestException`. The
  forge's response stays reachable as `getPrevious()`; any other `400` is unchanged.
- GitHub `release($tagOrId)` now finds a release by its numeric id, as `Feature::FindRelease`
  promises. An all-digit value no tag answers to is looked up as a release id; a tag still wins.
- GitHub `contents()` (and `batch()->contents()`) now return the whole file for files between
  1 and 100 MB, read through the blob endpoint. They used to come back with an empty `content`
  next to the real `sha`. A directory path now throws `OutOfScopeException::notAFile()` naming
  the path instead of an `ErrorException`.
- A GitLab merge request webhook now maps the pull request's `url` (GitLab sends `url`, not
  `web_url`) and its `author` — the triggering `user` when it is the merge request's author,
  otherwise `null`. Both used to be `null`.
- GitLab and Bitbucket pull requests (REST and webhook) now report `draft: true` for a draft.
  It was always `false`.
- Bitbucket `pullRequests($path, 'closed')` now includes superseded pull requests: it asks for
  `state=DECLINED&state=SUPERSEDED`, matching what `ResourceState` calls closed. It used to ask
  for declined ones only.
- `batch()->pullRequest()` now throws `InvalidArgumentException` naming a reference that is not
  `path#number` (number 1 or more) before sending anything. A reference with no `#` failed with
  an `ErrorException`, and `acme/app#x` requested `/pulls/0`. `Git::fake()` refuses the same.
- Bitbucket `listWebhooks()` reports a hook as `pull_request` only when it is subscribed to all
  four pull request events (created, updated, fulfilled, rejected). A partial subscription is
  now reported by its native names (`pullrequest:created`), instead of looking complete.
- `webhooks()->register()` no longer returns an existing hook at the same URL that has other
  events: it throws `InvalidArgumentException` naming the hook, so `register(events: ['push',
  'pull_request'])` cannot silently keep a push-only hook. The same events in any order (and
  their native spellings, like GitLab's `merge_requests_events`) still return the existing hook
  without a request.
- `Git::fake()` list reads now page like the real drivers: a `$perPage` below 1 throws
  `InvalidArgumentException`, a size above 100 is capped at 100, and the seeded items are sliced
  by page with `hasMore` set — so `commits()->perPage(2)->get(3)` answers the fifth seeded
  commit instead of the whole set again.
- `Git::fake()` clone URLs now carry the username the real driver uses: `token` (or
  `x-access-token` for an installation token) on GitHub, `oauth2` on GitLab, the caller's on
  Bitbucket. The fake used the caller's username on every forge.

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
