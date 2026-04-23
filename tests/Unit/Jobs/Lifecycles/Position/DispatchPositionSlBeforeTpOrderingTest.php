<?php

declare(strict_types=1);

/**
 * Pins the opening-chain order: stop-loss MUST be placed on the exchange
 * before the take-profit on every exchange that splits the two calls. A
 * LIMIT TP can fill instantly on fast-moving tokens (LAB #121 at
 * 16:57:29, BSB #109 earlier today), so placing TP first opens a window
 * where the position closes before the SL is registered, producing a
 * Binance -4509 "GTE can only be used with open positions" and
 * cascading into a realised-loss cancel workflow.
 *
 * Flipping the placement order turns the race into an invariant: once
 * SL is on the book as a conditional algo, no sequence of fills can
 * leave the position unprotected, and a TP that fires immediately after
 * is just a clean close.
 *
 * Bitget is exempt — its `PlacePositionTpslJob` ships TP + SL in a
 * single API call, so the race window doesn't exist on that lifecycle.
 */
function assertSlPlacedBeforeTp(string $dispatchJobFqcn): void
{
    $reflection = new ReflectionClass($dispatchJobFqcn);
    $source = file_get_contents((string) $reflection->getFileName());

    expect($source)->toBeString();

    $slPosition = mb_strpos($source, 'PlaceStopLossOrderLifecycle::class');
    $tpPosition = mb_strpos($source, 'PlaceProfitOrderLifecycle::class');

    expect($slPosition)->not->toBeFalse(
        "{$dispatchJobFqcn}: PlaceStopLossOrderLifecycle not referenced"
    );
    expect($tpPosition)->not->toBeFalse(
        "{$dispatchJobFqcn}: PlaceProfitOrderLifecycle not referenced"
    );

    expect($slPosition)->toBeLessThan(
        $tpPosition,
        "{$dispatchJobFqcn}: stop-loss must be placed before take-profit to "
        .'avoid the TP-fills-before-SL race (Binance -4509)'
    );
}

it('places stop-loss before take-profit on Binance DispatchPositionJob', function (): void {
    assertSlPlacedBeforeTp(
        Kraite\Core\Jobs\Lifecycles\Position\Binance\DispatchPositionJob::class
    );
});

it('places stop-loss before take-profit on Bybit DispatchPositionJob', function (): void {
    assertSlPlacedBeforeTp(
        Kraite\Core\Jobs\Lifecycles\Position\Bybit\DispatchPositionJob::class
    );
});

it('places stop-loss before take-profit on KuCoin DispatchPositionJob', function (): void {
    assertSlPlacedBeforeTp(
        Kraite\Core\Jobs\Lifecycles\Position\Kucoin\DispatchPositionJob::class
    );
});
