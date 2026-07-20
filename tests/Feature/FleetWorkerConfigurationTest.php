<?php

declare(strict_types=1);

it('gives Athena indicator work processing margin without changing its safety lanes', function (): void {
    $athenaWorkers = config('kraite.horizon.workers.athena');

    expect($athenaWorkers)->toBe([
        'user-data-stream' => ['processes' => 5],
        'indicators' => ['processes' => 16],
        'athena' => ['processes' => 1],
    ])->and(config('kraite.horizon.workers.tyche.indicators.processes'))->toBe(8)
        ->and(config('kraite.horizon.workers.tyche.cronjobs.processes'))->toBe(6);
});
