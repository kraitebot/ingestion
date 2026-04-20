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

            'default-supervisor' => [
                'connection' => 'redis',
                'queue' => ['default'],
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

            'step-dispatcher-supervisor' => [
                'connection' => 'redis',
                'queue' => ['step-dispatcher'],
                'processes' => 3,
                'timeout' => 120,
                'sleep' => 1,
                'tries' => 1,
                'backoff' => 10,
                'memory' => 256,
            ],

        ], [
            // Dynamic hostname-based queue (e.g., 'server-1', 'worker-2', etc.)
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
            'default-supervisor' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'processes' => 50,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'priority-supervisor' => [
                'connection' => 'redis',
                'queue' => ['priority'],
                'processes' => 10,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'step-dispatcher-supervisor' => [
                'connection' => 'redis',
                'queue' => ['step-dispatcher'],
                'processes' => 10,
                'timeout' => 120,
                'sleep' => 1,
                'tries' => 1,
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
        ],

        'worker1' => [
            'default-supervisor' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'processes' => 50,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'priority-supervisor' => [
                'connection' => 'redis',
                'queue' => ['priority'],
                'processes' => 10,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'step-dispatcher-supervisor' => [
                'connection' => 'redis',
                'queue' => ['step-dispatcher'],
                'processes' => 10,
                'timeout' => 120,
                'sleep' => 1,
                'tries' => 1,
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
            'default-supervisor' => [
                'connection' => 'redis',
                'queue' => ['default'],
                'processes' => 50,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'priority-supervisor' => [
                'connection' => 'redis',
                'queue' => ['priority'],
                'processes' => 10,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'step-dispatcher-supervisor' => [
                'connection' => 'redis',
                'queue' => ['step-dispatcher'],
                'processes' => 10,
                'timeout' => 120,
                'sleep' => 1,
                'tries' => 1,
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
