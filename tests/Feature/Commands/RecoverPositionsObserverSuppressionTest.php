<?php

declare(strict_types=1);

/**
 * Pins the observer-suppression contract for `mirrorOrderStatuses()`.
 *
 * `apiSync()` calls `updateSaving([...])` internally, which fires
 * `OrderObserver::updated()`. During recovery, that observer would
 * dispatch ClosePositionJob / ApplyWapJob / PreparePositionReplacementJob /
 * etc. against a half-recovered DB — and Step creation flips the
 * dispatcher flag back on via StepObserver::created(), undoing the
 * deactivation that recovery just set up.
 *
 * Recovery MUST suppress order events during the mirror pass and let
 * the remaining recovery phases reconcile state intentionally before
 * the dispatcher is re-activated in the finally block.
 */
it('mirrorOrderStatuses wraps apiSync in withoutEvents to suppress observer cascade', function (): void {
    $source = file_get_contents(
        base_path('vendor/kraitebot/core/src/Commands/RecoverPositionsCommand.php')
    );

    expect($source)->toMatch('/Order::withoutEvents\(.*apiSync/s')
        ->and($source)->not->toMatch('/foreach \(\$orders as \$order\) \{[^}]*?\$order->apiSync\(\);/s');
});
