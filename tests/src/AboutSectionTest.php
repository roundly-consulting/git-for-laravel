<?php

declare(strict_types=1);

/**
 * The secret-safe `about` capture (A).
 *
 * Purchases #13 is the bug this exists for: the fleet's most credential-heavy `about`
 * section was guarded by negative assertions against `app(Kernel::class)->output()`,
 * which returns `''` — every "does not leak" check was vacuous, passing against empty
 * output.
 *
 * Git's section is worth pinning precisely because it is credential-AWARE by design: it
 * reports *which* providers have a usable credential, which means it reads every token,
 * webhook secret, OAuth client secret and App private key in the config and must render
 * a name while never rendering the value it inspected. That is the exact shape where a
 * "helpful" `about` line leaks a forge token into a support-ticket paste.
 */
it('names the credentialed providers without rendering a single credential', function (): void {
    config()->set('git.providers.github.token', 'ghp_live_github_token_value');
    config()->set('git.providers.github.webhook_secret', 'github-webhook-signing-secret');
    config()->set('git.providers.github.app.private_key', '-----BEGIN RSA PRIVATE KEY-----MIIEow==');
    config()->set('git.providers.github.oauth.client_secret', 'github-oauth-client-secret');
    config()->set('git.providers.gitlab.token', 'glpat_live_gitlab_token_value');
    config()->set('git.providers.gitlab.oauth.client_secret', 'gitlab-oauth-client-secret');
    config()->set('git.providers.bitbucket.token', null);

    config()->set('git.providers.bitbucket.rateLimits.enabled', false);
    config()->set('git.webhooks.enabled', true);
    config()->set('git.webhooks.path', 'git/webhooks');
    config()->set('git.cache.enabled', true);

    expect('git')->toLeakNoSecrets(
        secrets: [
            // Every credential the section reads to decide what to print.
            'ghp_live_github_token_value',
            'glpat_live_gitlab_token_value',
            'github-webhook-signing-secret',
            'github-oauth-client-secret',
            'gitlab-oauth-client-secret',
            '-----BEGIN RSA PRIVATE KEY-----',
            'MIIEow==',
        ],
        mustRender: [
            // The four labels, so the negative half can never pass over a section that
            // simply failed to render.
            'Providers',
            'Rate limiting',
            'Webhooks',
            'Conditional caching',
            // The positive proof that the credential-aware lines are REPORTING rather
            // than silently empty: github and gitlab have tokens and must be named,
            // bitbucket has none. This is what makes the leak check meaningful — the
            // section demonstrably inspected the secrets above and printed only names.
            'github, gitlab',
            // Bitbucket's limiter is off, so the throttled list must name the other two.
            'git/webhooks',
        ],
    );
});

/**
 * The counterpart: with no credential configured at all, the providers line must say so
 * rather than render an empty string. An `about` line that goes blank when everything is
 * unconfigured is indistinguishable from one that broke.
 */
it('reports NONE rather than an empty line when no provider is credentialed', function (): void {
    config()->set('git.providers.github.token', null);
    config()->set('git.providers.gitlab.token', null);
    config()->set('git.providers.bitbucket.token', null);
    config()->set('git.webhooks.enabled', false);
    config()->set('git.cache.enabled', false);

    expect('git')->toLeakNoSecrets(
        secrets: ['ghp_live_github_token_value'],
        mustRender: ['Providers', 'NONE', 'Webhooks', 'OFF'],
    );
});
