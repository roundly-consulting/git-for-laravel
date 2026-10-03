<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Per-provider connection settings. Each provider may carry a default
    | access token (so `Git::github()` works with no explicit credential),
    | an optional webhook secret, request timeout, retry policy, client-side
    | rate limit, and extra HTTP options/headers.
    |
    */
    'providers' => [
        'github' => [
            'url' => env('GITHUB_API_URL', 'https://api.github.com'),
            'token' => env('GITHUB_TOKEN'),
            'webhook_secret' => env('GITHUB_WEBHOOK_SECRET'),
            'timeout' => env('GITHUB_TIMEOUT', 10),
            'retry' => [
                'times' => env('GITHUB_RETRY_TIMES', 1),
                'backoff' => env('GITHUB_RETRY_BACKOFF', 0),
            ],
            'rateLimits' => [
                // false = send with no client-side limiter at all.
                'enabled' => env('GITHUB_RATELIMIT_ENABLED', true),
                'owner' => env('GITHUB_RATELIMIT_OWNER', 'app'),
                'maxAttempts' => env('GITHUB_RATELIMIT', 5000),
                // second | minute | hour | day
                'timespan' => env('GITHUB_RATELIMIT_TIMESPAN', 'hour'),
                // Honour the provider's own Retry-After / X-RateLimit-* headers.
                'adaptive' => env('GITHUB_RATELIMIT_ADAPTIVE', true),
                // Max defer in ms before failing fast (null = wait/pace forever).
                'max_wait' => env('GITHUB_RATELIMIT_MAX_WAIT'),
                // Random jitter in ms added to each defer (null = none).
                'jitter' => env('GITHUB_RATELIMIT_JITTER'),
            ],
            'options' => [
                'headers' => [
                    'User-Agent' => env('GIT_USER_AGENT', env('APP_NAME', 'GitHttp/1.0')),
                    'X-GitHub-Api-Version' => env('GITHUB_API_VERSION', '2022-11-28'),
                ],
            ],

            // GitHub App authentication (self-refreshing installation tokens).
            // When `id`, `installation_id` and `private_key` are all set,
            // `Git::github()` mints installation tokens automatically; otherwise it
            // uses `token`. `Git::githubApp()` needs only `id` + `private_key`.
            // `private_key` may be a PEM string or a file path.
            'app' => [
                'id' => env('GITHUB_APP_ID'),
                'installation_id' => env('GITHUB_APP_INSTALLATION_ID'),
                'private_key' => env('GITHUB_APP_PRIVATE_KEY'),
                // The app's public slug, from https://github.com/apps/<slug>. Only an
                // install URL needs it, but it lives here so the whole app contract is
                // one config block rather than half of it in each consumer.
                'slug' => env('GITHUB_APP_SLUG'),
                // The default scope for a per-operation mint. Narrowing at mint time is
                // the point of an installation token; this is the set a clone + push +
                // pull-request workflow needs, and nothing else.
                'permissions' => [
                    'contents' => 'write',
                    'pull_requests' => 'write',
                    'metadata' => 'read',
                ],
            ],

            // OAuth credentials (self-refreshing access tokens).
            'oauth' => [
                'client_id' => env('GITHUB_OAUTH_CLIENT_ID'),
                'client_secret' => env('GITHUB_OAUTH_CLIENT_SECRET'),
                'token_url' => env('GITHUB_OAUTH_TOKEN_URL', 'https://github.com/login/oauth/access_token'),
            ],
        ],

        'gitlab' => [
            'url' => env('GITLAB_API_URL', 'https://gitlab.com'),
            'token' => env('GITLAB_TOKEN'),
            'webhook_secret' => env('GITLAB_WEBHOOK_SECRET'),
            'timeout' => env('GITLAB_TIMEOUT', 10),
            'retry' => [
                'times' => env('GITLAB_RETRY_TIMES', 1),
                'backoff' => env('GITLAB_RETRY_BACKOFF', 0),
            ],
            'rateLimits' => [
                'enabled' => env('GITLAB_RATELIMIT_ENABLED', true),
                'owner' => env('GITLAB_RATELIMIT_OWNER', 'app'),
                'maxAttempts' => env('GITLAB_RATELIMIT', 10),
                'timespan' => env('GITLAB_RATELIMIT_TIMESPAN', 'second'),
                'adaptive' => env('GITLAB_RATELIMIT_ADAPTIVE', true),
                'max_wait' => env('GITLAB_RATELIMIT_MAX_WAIT'),
                'jitter' => env('GITLAB_RATELIMIT_JITTER'),
            ],
            'options' => [
                'headers' => [
                    'User-Agent' => env('GIT_USER_AGENT', env('APP_NAME', 'GitHttp/1.0')),
                ],
            ],

            // OAuth credentials (self-refreshing access tokens).
            'oauth' => [
                'client_id' => env('GITLAB_OAUTH_CLIENT_ID'),
                'client_secret' => env('GITLAB_OAUTH_CLIENT_SECRET'),
                'token_url' => env('GITLAB_OAUTH_TOKEN_URL', 'https://gitlab.com/oauth/token'),
            ],
        ],

        'bitbucket' => [
            'url' => env('BITBUCKET_API_URL', 'https://api.bitbucket.org'),
            'token' => env('BITBUCKET_TOKEN'),
            'webhook_secret' => env('BITBUCKET_WEBHOOK_SECRET'),
            'timeout' => env('BITBUCKET_TIMEOUT', 10),
            'retry' => [
                'times' => env('BITBUCKET_RETRY_TIMES', 1),
                'backoff' => env('BITBUCKET_RETRY_BACKOFF', 0),
            ],
            'rateLimits' => [
                'enabled' => env('BITBUCKET_RATELIMIT_ENABLED', true),
                'owner' => env('BITBUCKET_RATELIMIT_OWNER', 'app'),
                'maxAttempts' => env('BITBUCKET_RATELIMIT', 1000),
                'timespan' => env('BITBUCKET_RATELIMIT_TIMESPAN', 'hour'),
                'adaptive' => env('BITBUCKET_RATELIMIT_ADAPTIVE', true),
                'max_wait' => env('BITBUCKET_RATELIMIT_MAX_WAIT'),
                'jitter' => env('BITBUCKET_RATELIMIT_JITTER'),
            ],
            'options' => [
                'headers' => [
                    'User-Agent' => env('GIT_USER_AGENT', env('APP_NAME', 'GitHttp/1.0')),
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Conditional-request caching
    |--------------------------------------------------------------------------
    |
    | When enabled, ETags returned by providers are stored in the cache and
    | replayed via `If-None-Match`; a 304 response is served from cache.
    |
    */
    'cache' => [
        'enabled' => env('GIT_CACHE_ENABLED', false),
        'store' => env('GIT_CACHE_STORE'),
        'ttl' => env('GIT_CACHE_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request logging
    |--------------------------------------------------------------------------
    |
    | Logs method, URL, status, and duration at debug level. Auth headers and
    | request bodies are never logged.
    |
    */
    'logging' => [
        'enabled' => env('GIT_LOGGING_ENABLED', false),
        'channel' => env('GIT_LOGGING_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook receiving
    |--------------------------------------------------------------------------
    |
    | Opt-in route that verifies incoming provider webhooks and dispatches
    | typed Laravel events.
    |
    */
    'webhooks' => [
        // Handed over raw and parsed strictly where it is read: `1/true/on/yes` enable the
        // route, `0/false/off/no` keep it off, and anything else throws at boot.
        'enabled' => env('GIT_WEBHOOKS_ENABLED', false),
        'path' => env('GIT_WEBHOOKS_PATH', 'git/webhooks'),
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrent batch fetches
    |--------------------------------------------------------------------------
    |
    | Caps the size of each concurrent HTTP pool issued by `provider()->batch()`.
    | Input lists larger than this are chunked into sequential pools.
    |
    */
    'batch' => [
        'concurrency' => env('GIT_BATCH_CONCURRENCY', 25),
    ],
];
