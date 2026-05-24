<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    'use' => 'default',

    'env' => env('HORIZON_ENV', env('APP_ENV', 'production')),

    'prefix' => env('HORIZON_PREFIX', Str::slug((string) env('APP_NAME', 'laravel'), '_').'_'.env('HORIZON_ENV', env('APP_ENV', 'env')).'_horizon:'),

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

            'cronjobs-supervisor' => [
                'connection' => 'redis',
                'queue' => ['cronjobs'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'positions-supervisor' => [
                'connection' => 'redis',
                'queue' => ['positions'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 2,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'orders-supervisor' => [
                'connection' => 'redis',
                'queue' => ['orders'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'indicators-supervisor' => [
                'connection' => 'redis',
                'queue' => ['indicators'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'priority-supervisor' => [
                'connection' => 'redis',
                'queue' => ['priority'],
                'balance' => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses' => 1,
                'maxProcesses' => 2,
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
                'processes' => 1,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

        ], [
            mb_strtolower(str_replace('-', '', gethostname() ?: 'unknown')).'-supervisor' => [
                'connection' => 'redis',
                'queue' => [mb_strtolower(str_replace('-', '', gethostname() ?: 'unknown'))],
                'processes' => 1,
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
                'processes' => 5,
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
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'indicators-supervisor' => [
                'connection' => 'redis',
                'queue' => ['indicators'],
                'processes' => 20,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'priority-supervisor' => [
                'connection' => 'redis',
                'queue' => ['priority'],
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'ingestion-supervisor' => [
                'connection' => 'redis',
                'queue' => ['ingestion'],
                'processes' => 1,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'user-data-stream-supervisor' => [
                'connection' => 'redis',
                'queue' => ['user-data-stream'],
                'processes' => 5,
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
                'processes' => 5,
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
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'indicators-supervisor' => [
                'connection' => 'redis',
                'queue' => ['indicators'],
                'processes' => 20,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'priority-supervisor' => [
                'connection' => 'redis',
                'queue' => ['priority'],
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'worker1-supervisor' => [
                'connection' => 'redis',
                'queue' => ['worker1'],
                'processes' => 1,
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
                'processes' => 5,
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
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'indicators-supervisor' => [
                'connection' => 'redis',
                'queue' => ['indicators'],
                'processes' => 20,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'priority-supervisor' => [
                'connection' => 'redis',
                'queue' => ['priority'],
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],

            'worker2-supervisor' => [
                'connection' => 'redis',
                'queue' => ['worker2'],
                'processes' => 1,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
        ],

        // ATHENA — ingestion + web. Consumes user-data-stream frames (same box
        // as the WS daemon source for lowest-latency frame handling). The
        // hostname queue with 1 process is the connectivity-test queue used
        // during account onboarding to verify athena can reach the user's
        // exchange account with its public IP.
        'athena' => [
            'user-data-stream-supervisor' => [
                'connection' => 'redis',
                'queue' => ['user-data-stream'],
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
            'athena-supervisor' => [
                'connection' => 'redis',
                'queue' => ['athena'],
                'processes' => 1,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
        ],

        // EOS — trading worker. Sized for CX23 (2 vCPU / 4 GB). Workers are
        // I/O-bound waiting on Binance HTTP, so process counts well exceed
        // core count. eos/iris/nyx are interchangeable Horizon consumers
        // competing on the same positions/orders/priority queues — no
        // per-account-to-box binding by design. Three distinct public IPs
        // spread Binance API call load across workers naturally.
        'eos' => [
            'positions-supervisor' => [
                'connection' => 'redis',
                'queue' => ['positions'],
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
            'orders-supervisor' => [
                'connection' => 'redis',
                'queue' => ['orders'],
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
            'priority-supervisor' => [
                'connection' => 'redis',
                'queue' => ['priority'],
                'processes' => 3,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
            'eos-supervisor' => [
                'connection' => 'redis',
                'queue' => ['eos'],
                'processes' => 1,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
        ],

        // IRIS — trading worker. Mirror of eos. Adds a second public IP to
        // the trading-worker pool so Binance API call load spreads across
        // two IPs naturally. No per-account-to-box binding (see eos comment).
        'iris' => [
            'positions-supervisor' => [
                'connection' => 'redis',
                'queue' => ['positions'],
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
            'orders-supervisor' => [
                'connection' => 'redis',
                'queue' => ['orders'],
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
            'priority-supervisor' => [
                'connection' => 'redis',
                'queue' => ['priority'],
                'processes' => 3,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
            'iris-supervisor' => [
                'connection' => 'redis',
                'queue' => ['iris'],
                'processes' => 1,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
        ],

        // NYX — third trading worker. Joined 2026-05-24 to add a third public
        // IP + raw throughput capacity to the trading pool. Identical
        // supervisor shape to eos/iris; interchangeable Horizon consumer
        // with no per-account-to-box binding. Primordial goddess of night —
        // pairs with Eos (dawn) in the fleet's temporal-symmetry theme.
        'nyx' => [
            'positions-supervisor' => [
                'connection' => 'redis',
                'queue' => ['positions'],
                'processes' => 5,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
            'orders-supervisor' => [
                'connection' => 'redis',
                'queue' => ['orders'],
                'processes' => 8,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
            'priority-supervisor' => [
                'connection' => 'redis',
                'queue' => ['priority'],
                'processes' => 3,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
            'nyx-supervisor' => [
                'connection' => 'redis',
                'queue' => ['nyx'],
                'processes' => 1,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
        ],

        // TYCHE — isolated worker for indicators + cronjobs. Keeps the
        // TAAPI-throttled indicator queue from starving position/order
        // processing on eos/iris/nyx. Also processes scheduler-triggered cronjobs
        // (kraite:cron-fetch-klines, kraite:cron-sync-orders, etc.).
        'tyche' => [
            'indicators-supervisor' => [
                'connection' => 'redis',
                'queue' => ['indicators'],
                'processes' => 10,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
            'cronjobs-supervisor' => [
                'connection' => 'redis',
                'queue' => ['cronjobs'],
                'processes' => 3,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
            'tyche-supervisor' => [
                'connection' => 'redis',
                'queue' => ['tyche'],
                'processes' => 1,
                'timeout' => 0,
                'sleep' => 1,
                'tries' => 5,
                'backoff' => 10,
                'memory' => 256,
            ],
        ],
    ],
];
