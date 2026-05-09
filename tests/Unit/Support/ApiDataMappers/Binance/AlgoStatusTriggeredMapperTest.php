<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper;

/**
 * Faithful-status mapping: Binance's `algoStatus=TRIGGERED` represents
 * a distinct exchange event — the algo's trigger condition fired and
 * Binance auto-placed a separate reduce-only MARKET to flat the
 * position. That is NOT a fill of the algo order itself; the algo is
 * the trigger record, the close-MARKET is the execution record.
 *
 * Conflating both into our local `FILLED` status erases the audit
 * trail: when the close-flow's CancelAlgoOpenOrdersJob later writes
 * CANCELLED back over the row, the original `triggered` truth is
 * indistinguishable from a routine cancel. Reports like "which
 * positions closed by SL hit" become un-queryable without inferring
 * from `position.closing_price` matching the trigger price.
 *
 * The mapper must surface `TRIGGERED` as its own status, downstream
 * consumers (OrderObserver close-trigger, CancelAlgoOpenOrdersJob's
 * skip list, observer pin) handle it as a distinct terminal state.
 */
function buildAlgoQueryResponse(string $algoStatus): Response
{
    $body = json_encode([
        [
            'algoId' => 1000001596054504,
            'algoStatus' => $algoStatus,
            'algoType' => 'CONDITIONAL',
            'symbol' => 'APEUSDT',
            'side' => 'SELL',
            'positionSide' => 'LONG',
            'triggerPrice' => '0.16460',
            'workingType' => 'MARK_PRICE',
            'closePosition' => true,
            'executedQty' => '0',
            'quantity' => '0',
        ],
    ]);

    return new Response(200, [], $body);
}

it('preserves TRIGGERED as its own status (no FILLED conflation)', function (): void {
    $mapper = new BinanceApiDataMapper;
    $resolved = $mapper->resolveAlgoOrderQueryResponse(buildAlgoQueryResponse('TRIGGERED'));

    expect($resolved['status'])->toBe('TRIGGERED');
});

it('keeps NEW unchanged (sanity)', function (): void {
    $mapper = new BinanceApiDataMapper;
    $resolved = $mapper->resolveAlgoOrderQueryResponse(buildAlgoQueryResponse('NEW'));

    expect($resolved['status'])->toBe('NEW');
});

it('still maps CANCELLED / CANCELED to CANCELLED (sanity)', function (string $exchangeStatus): void {
    $mapper = new BinanceApiDataMapper;
    $resolved = $mapper->resolveAlgoOrderQueryResponse(buildAlgoQueryResponse($exchangeStatus));

    expect($resolved['status'])->toBe('CANCELLED');
})->with([
    'CANCELLED' => ['CANCELLED'],
    'CANCELED' => ['CANCELED'],
    'EXPIRED' => ['EXPIRED'],
]);

it('still maps PARTIALLY_TRIGGERED to PARTIALLY_FILLED (sanity)', function (): void {
    $mapper = new BinanceApiDataMapper;
    $resolved = $mapper->resolveAlgoOrderQueryResponse(buildAlgoQueryResponse('PARTIALLY_TRIGGERED'));

    expect($resolved['status'])->toBe('PARTIALLY_FILLED');
});

it('still maps EXECUTING to NEW (sanity)', function (): void {
    $mapper = new BinanceApiDataMapper;
    $resolved = $mapper->resolveAlgoOrderQueryResponse(buildAlgoQueryResponse('EXECUTING'));

    expect($resolved['status'])->toBe('NEW');
});

it('preserves TRIGGERED on the place-algo response too (creating + querying use same mapper)', function (): void {
    $mapper = new BinanceApiDataMapper;

    $placeBody = json_encode([
        'algoId' => 1000001596054504,
        'clientAlgoId' => 'abc-123',
        'algoType' => 'CONDITIONAL',
        'symbol' => 'APEUSDT',
        'side' => 'SELL',
        'positionSide' => 'LONG',
        'quantity' => '0',
        'algoStatus' => 'TRIGGERED',
        'triggerPrice' => '0.16460',
        'workingType' => 'MARK_PRICE',
        'closePosition' => true,
        'createTime' => 1778316844000,
        'updateTime' => 1778316844060,
    ]);

    $resolved = $mapper->resolvePlaceAlgoOrderResponse(new Response(200, [], $placeBody));

    expect($resolved['status'])->toBe('TRIGGERED');
});
