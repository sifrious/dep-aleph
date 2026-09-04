<?php

declare(strict_types=1);

return [
    'connection' => null,

    'connectors' => [
        'disabled' => [],
    ],

    'gutenberg' => [
        'base_url' => 'https://www.gutenberg.org',
        'cache_directory' => storage_path('app/aleph/gutenberg'),
        'max_attempts' => 3,
        'retry_delay_milliseconds' => 200,
    ],

    'normalization' => [
        'cache_enabled' => true,
        'cache_ttl' => 604800,
    ],

    'http' => [
        'user_agent' => 'AlephCrawler/0.1 (+https://github.com/sifrious/aleph)',
        'connect_timeout' => 5,
        'timeout' => 15,
        'max_response_bytes' => 5242880,
        'max_redirects' => 5,
        'delay_ms' => 1000,
        'retries' => 1,
        'respect_robots' => true,
    ],

    'web_sources' => [

        'ahsd' => [
            'name' => 'Abington Heights School District',

            'seeds' => [
                'https://www.ahsd.org/',
                'https://hs.ahsd.org/',
                'https://ms.ahsd.org/',
                'https://cse.ahsd.org/',
                'https://wav.ahsd.org/',
            ],

            'allowed_hosts' => [
                'ahsd.org',
                '*.ahsd.org',
            ],

            'excluded' => [
                '*/login*',
                '*/logout*',
                '*/signin*',
                '*/signout*',
                '*/account*',
                '*/search*',
                '*/print*',
            ],

            'query_parameters' => [],

            'calendar_signals' => [
                '*calendar',
                '*calendar/*',
            ],

            'limits' => [
                'max_pages' => 200,
                'max_depth' => 3,
            ],
        ],

    ],
];
