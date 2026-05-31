<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Horizon Configuration
|--------------------------------------------------------------------------
|
| The `environments` block at the bottom of this file is a thin transformer
| that reads from `config('kraite.horizon')`. The actual fleet topology
| (which workers serve which logical queues, with what process counts) is
| declared in `config/kraite.php` under the `horizon` key — see that file
| for the source of truth.
|
| The transformer composes the PHYSICAL queue name as `{hostname}-{logical}`
| for each declared (worker, logical-queue) pair (e.g. `eos-positions`),
| with the special case that when the logical name already equals the
| hostname (the per-hostname queue like `eos`), no prefix is added. This
| matches the StepRouter's `buildPhysicalQueue()` logic so the dispatcher
| and Horizon agree on the queue namespace.
|
| Drift between `kraite.horizon.workers` (source of truth for both files)
| and `kraite.queue_subscriptions` (StepRouter's candidate-set lookup) is
| caught by the `kraite:verify-horizon-topology` artisan command — wedged
| steps (pushed to a queue with no consumer) would silently sit in Redis
| otherwise, so the command runs in CI and on each box's boot.
*/

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

    // NOTE: populated at ServiceProvider boot time by
    // CoreServiceProvider::syncHorizonEnvironmentsFromKraiteConfig() —
    // NOT inline here. Config files load in alphabetical order, which
    // means `horizon.php` (h) loads BEFORE `kraite.php` (k); an inline
    // transformer reading `config('kraite.horizon.workers')` here
    // would always get an empty array because kraite.php hasn't loaded
    // yet. Deferring to boot() solves the ordering naturally — by the
    // time any ServiceProvider boots, every config file has loaded.
    'environments' => [],
];
