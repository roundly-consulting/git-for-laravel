<?php

declare(strict_types=1);

it('ships exactly the config keys it reads', function (): void {
    expect(realpath(__DIR__.'/../../config/git.php'))->toSatisfyConfigContract([
        realpath(__DIR__.'/../../src'),
        // `routes/git-webhooks.php` reads `git.webhooks.middleware` and `…path`. It is
        // real, shipped, loaded source — it is just not under `src/`, and the scraper
        // walks each srcDir (plus a sibling `database/`) and nothing else. Left out,
        // `middleware` scrapes as unread and looks exactly like a dead key.
        realpath(__DIR__.'/../../routes'),
    ], [
        // Every provider section is read wholesale under a runtime driver key —
        // `config("git.providers.{$this->key()}")` — so per-leaf proof comes from the
        // literal offsets the code indexes it with. The base paths carry a `*` for the
        // driver: the leaf is proven, the driver never is.
        'sectionVariables' => [
            'BaseProvider.php' => ['$http' => 'git.providers.*', '$retry' => 'git.providers.*.retry'],
            'InteractsWithRateLimits.php' => ['$config' => 'git.providers.*.rateLimits'],
        ],

        // `options` is handed to Guzzle verbatim (`Http::withOptions($http['options'])`).
        // These header leaves are shipped defaults, not a schema this package reads: no
        // git code names `User-Agent` or `X-GitHub-Api-Version`, and none should — the
        // whole point is that a host can add any header without a change here. So the
        // claim "nothing reads this leaf, and that is correct" is *true*, which is the
        // only thing that makes allowUnread honest rather than a way to mute a real
        // finding. It is rot-checked too: rename one and this entry goes stale and fails.
        'allowUnread' => [
            // The app permission map is read WHOLESALE and sent to GitHub verbatim
            // (`InstallationTokenScope::forRepositories()` → the access-token body), for
            // the same reason as the Guzzle headers below: it is a shipped default a
            // deployment may change, not a schema this package indexes leaf by leaf.
            // Nothing here names `contents`, and nothing should — an app granted a
            // different set changes only this file.
            'git.providers.github.app.permissions.contents',
            'git.providers.github.app.permissions.pull_requests',
            'git.providers.github.app.permissions.metadata',
            'git.providers.github.options.headers.User-Agent',
            'git.providers.github.options.headers.X-GitHub-Api-Version',
            'git.providers.gitlab.options.headers.User-Agent',
            'git.providers.bitbucket.options.headers.User-Agent',
        ],
    ]);
});
