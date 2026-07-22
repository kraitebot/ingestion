<?php

declare(strict_types=1);

it('keeps the TAAPI Expert throttle just below the provider ceiling with a matching request delay', function (): void {
    $requestsPerWindow = config('kraite.throttlers.taapi.requests_per_window');
    $windowSeconds = config('kraite.throttlers.taapi.window_seconds');
    $minDelayMs = config('kraite.throttlers.taapi.min_delay_between_requests_ms');

    expect($requestsPerWindow)->toBe(72)
        ->and($windowSeconds)->toBe(15)
        ->and($minDelayMs)->toBe(208)
        ->and(config('kraite.throttlers.taapi.safety_threshold'))->toBe(1.0)
        // The serialized delay must fit the full request budget inside the
        // window, otherwise the delay caps throughput below the request cap.
        ->and($requestsPerWindow * $minDelayMs)->toBeLessThanOrEqual($windowSeconds * 1000);
});
