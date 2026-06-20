<?php

return [
    'providers' => [
        'github' => [
            'url' => env('GITHUB_API_URL', 'https://api.github.com'),
            'timeout' => env('GITHUB_TIMEOUT', 10),
            'retry' => env('GITHUB_RETRY', 1),
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
            'timeout' => env('GITLAB_TIMEOUT', 10),
            'retry' => env('GITLAB_RETRY', 1),
            'rateLimits' => [
                'owner' => env('GITLAB_RATELIMIT_OWNER', 'app'),
                'maxAttempts' => env('GITLAB_RATELIMIT', 10),
                'timespan' => env('GITLAB_RATELIMIT_TIMESPAN', 'second'),
            ],
            'options' => [
                'headers' => [
                    'User-Agent' => env('GIT_USER_AGENT', env('APP_NAME', 'GitHttp/1.0')),
                    'X-GitHub-Api-Version' => env('GITLAB_API_VERSION', '2022-11-28'),
                ],
            ],
        ],

        'bitbucket' => [
            'url' => env('BITBUCKET_API_URL', 'https://bitbucket.org'),
            'timeout' => env('BITBUCKET_TIMEOUT', 10),
            'retry' => env('BITBUCKET_RETRY', 1),
            'rateLimits' => [
                'owner' => env('BITBUCKET_RATELIMIT_OWNER', 'app'),
                'maxAttempts' => env('BITBUCKET_RATELIMIT', 1000),
                'timespan' => env('BITBUCKET_RATELIMIT_TIMESPAN', 'hour'),
            ],
            'options' => [
                'headers' => [
                    'User-Agent' => env('GIT_USER_AGENT', env('APP_NAME', 'GitHttp/1.0')),
                    'X-GitHub-Api-Version' => env('BITBUCKET_API_VERSION', '2022-11-28'),
                ],
            ],
        ],
    ],
];
