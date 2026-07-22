<?php

declare(strict_types=1);

it('runs every production queue on the single resource-bounded Kraite host', function (): void {
    expect(config('kraite.horizon.workers'))->toBe([
        'kraite' => [
            'positions' => ['processes' => 2],
            'orders' => ['processes' => 3],
            'priority' => ['processes' => 1],
            'cronjobs' => ['processes' => 4],
            'indicators' => ['processes' => 8],
            'user-data-stream' => ['processes' => 1],
            'web' => ['processes' => 1],
            'kraite' => ['processes' => 1],
        ],
    ]);
});
