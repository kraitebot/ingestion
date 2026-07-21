<?php

declare(strict_types=1);

it('keeps the TAAPI Expert throttle about ten percent below the provider ceiling', function (): void {
    expect(config('kraite.throttlers.taapi.requests_per_window'))->toBe(68)
        ->and(config('kraite.throttlers.taapi.window_seconds'))->toBe(15)
        ->and(config('kraite.throttlers.taapi.min_delay_between_requests_ms'))->toBe(221)
        ->and(config('kraite.throttlers.taapi.safety_threshold'))->toBe(1.0);
});
