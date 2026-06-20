<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Per-provider connection settings. Each provider may carry a default
    | access token (so `Registry::github()` works with no explicit credential),
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
                'owner' => env('GITHUB_RATELIMIT_OWNER', 'app'),
                'maxAttempts' => env('GITHUB_RATELIMIT', 5000),
                'timespan' => env('GITHUB_RATELIMIT_TIMESPAN', 'hour'),
            ],
            'options' => [
                'headers' => [
                    'User-Agent' => env('GIT_USER_AGENT', env('APP_NAME', 'GitHttp/1.0')),
                    'X-GitHub-Api-Version' => env('GITHUB_API_VERSION', '2022-11-28'),
                ],
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
                'owner' => env('GITLAB_RATELIMIT_OWNER', 'app'),
                'maxAttempts' => env('GITLAB_RATELIMIT', 10),
                'timespan' => env('GITLAB_RATELIMIT_TIMESPAN', 'second'),
            ],
            'options' => [
                'headers' => [
                    'User-Agent' => env('GIT_USER_AGENT', env('APP_NAME', 'GitHttp/1.0')),
                ],
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
                'owner' => env('BITBUCKET_RATELIMIT_OWNER', 'app'),
                'maxAttempts' => env('BITBUCKET_RATELIMIT', 1000),
                'timespan' => env('BITBUCKET_RATELIMIT_TIMESPAN', 'hour'),
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
        'enabled' => env('GIT_WEBHOOKS_ENABLED', false),
        'path' => env('GIT_WEBHOOKS_PATH', 'git/webhooks'),
        'middleware' => ['api'],
    ],
];
