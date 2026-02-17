<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('returns servers status data', function () {
    $response = get('/analytics/api/health/servers-status');

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'servers',
        'expected_commits' => [
            'core',
            'ingestion',
        ],
    ]);
});

it('returns total dispatcher stats', function () {
    $response = get('/analytics/api/dispatcher/total-stats');

    $response->assertSuccessful();
    $response->assertJsonStructure([
        'pending',
        'dispatched',
        'running',
        'completed',
        'failed',
        'stopped',
        'skipped',
        'throttled',
        'child_pending',
        'child_dispatched',
        'child_running',
        'child_completed',
        'child_failed',
        'child_stopped',
        'child_skipped',
        'child_throttled',
        'steps_last_1h',
        'steps_last_4h',
        'steps_last_24h',
        'group_stats',
        'step_class_stats',
    ]);
});

it('returns header controls data', function () {
    $response = get('/analytics/api/dispatcher/header');

    $response->assertSuccessful();
});

it('returns hostname stats', function () {
    $response = get('/analytics/api/dispatcher/hostname-stats');

    $response->assertSuccessful();
});

it('returns artisan commands list', function () {
    $response = get('/analytics/api/artisan/commands');

    $response->assertSuccessful();
});

it('returns account list', function () {
    $response = get('/analytics/api/account/list');

    $response->assertSuccessful();
});
