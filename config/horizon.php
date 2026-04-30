<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', Str::slug((string) env('APP_NAME', 'laravel'), '_').'_'.env('APP_ENV', 'env').'_horizon:'),

    'middleware' => ['web'],

    'waits' => [
        'redis:default' => 60,
    ],

    'trim' => [
        'recent' => 30,
        'pending' => 30,
        'completed' => 30,
        'recent_failed' => 1440,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => true,

    'memory_limit' => 256,

    'defaults' => [],

    'environments' => [

        'local' => array_merge([

            'positions-supervisor' => [
                'connection' => 'redis',
                'queue' => ['positions'],
                'balance' => 'simple',
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'orders-supervisor' => [
                'connection' => 'redis',
                'queue' => ['orders'],
                'balance' => 'simple',
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'cronjobs-supervisor' => [
                'connection' => 'redis',
                'queue' => ['cronjobs'],
                'balance' => 'simple',
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'indicators-supervisor' => [
                'connection' => 'redis',
                'queue' => ['indicators'],
                'balance' => 'simple',
                'processes' => 30,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'priority-supervisor' => [
                'connection' => 'redis',
                'queue' => ['priority'],
                'balance' => 'simple',
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'user-data-stream-supervisor' => [
                'connection' => 'redis',
                'queue' => ['user-data-stream'],
                'balance' => 'simple',
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

        ], [
            // Dynamic hostname-based queue for server-specific work
            // (e.g., ConnectivityTestController).
            mb_strtolower(str_replace('-', '', gethostname() ?: 'unknown')).'-supervisor' => [
                'connection' => 'redis',
                'queue' => [mb_strtolower(str_replace('-', '', gethostname() ?: 'unknown'))],
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
        ]),

        'ingestion' => [
            'positions-supervisor' => [
                'connection' => 'redis',
                'queue' => ['positions'],
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'orders-supervisor' => [
                'connection' => 'redis',
                'queue' => ['orders'],
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'cronjobs-supervisor' => [
                'connection' => 'redis',
                'queue' => ['cronjobs'],
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'indicators-supervisor' => [
                'connection' => 'redis',
                'queue' => ['indicators'],
                'processes' => 30,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'priority-supervisor' => [
                'connection' => 'redis',
                'queue' => ['priority'],
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'ingestion-supervisor' => [
                'connection' => 'redis',
                'queue' => ['ingestion'],
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'user-data-stream-supervisor' => [
                'connection' => 'redis',
                'queue' => ['user-data-stream'],
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
        ],

        'worker1' => [
            'positions-supervisor' => [
                'connection' => 'redis',
                'queue' => ['positions'],
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'orders-supervisor' => [
                'connection' => 'redis',
                'queue' => ['orders'],
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'cronjobs-supervisor' => [
                'connection' => 'redis',
                'queue' => ['cronjobs'],
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'indicators-supervisor' => [
                'connection' => 'redis',
                'queue' => ['indicators'],
                'processes' => 30,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'priority-supervisor' => [
                'connection' => 'redis',
                'queue' => ['priority'],
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'worker1-supervisor' => [
                'connection' => 'redis',
                'queue' => ['worker1'],
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
        ],

        'worker2' => [
            'positions-supervisor' => [
                'connection' => 'redis',
                'queue' => ['positions'],
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'orders-supervisor' => [
                'connection' => 'redis',
                'queue' => ['orders'],
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'cronjobs-supervisor' => [
                'connection' => 'redis',
                'queue' => ['cronjobs'],
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'indicators-supervisor' => [
                'connection' => 'redis',
                'queue' => ['indicators'],
                'processes' => 30,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'priority-supervisor' => [
                'connection' => 'redis',
                'queue' => ['priority'],
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'worker2-supervisor' => [
                'connection' => 'redis',
                'queue' => ['worker2'],
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
        ],
    ],
];
