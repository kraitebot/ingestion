<?php

declare(strict_types=1);

it('runs every production queue on the single resource-bounded Kraite host', function (): void {
    expect(config('kraite.horizon.workers'))->toBe([
        'kraite' => [
            'positions' => ['processes' => 2],
            'orders' => ['processes' => 3],
            'priority' => ['processes' => 1],
            'cronjobs' => ['processes' => 4],
            'indicators' => ['processes' => 12],
            'user-data-stream' => ['processes' => 1],
            'web' => ['processes' => 1],
            'kraite' => ['processes' => 1],
        ],
    ]);
});

it('monitors wait time on every physical Horizon queue', function (): void {
    expect(config('horizon.waits'))->toMatchArray([
        'redis:default' => 60,
        'redis:kraite-positions' => 60,
        'redis:kraite-orders' => 60,
        'redis:kraite-priority' => 60,
        'redis:kraite-cronjobs' => 60,
        'redis:kraite-indicators' => 60,
        'redis:kraite-user-data-stream' => 60,
        'redis:kraite-web' => 60,
        'redis:kraite' => 60,
    ]);
});
