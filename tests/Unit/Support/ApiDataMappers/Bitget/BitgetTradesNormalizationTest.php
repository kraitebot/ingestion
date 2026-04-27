<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Response;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetApiDataMapper;

/**
 * Regression guard for the SFPUSDT 2026-04-26 mis-recorded `closing_price`
 * incident: every Bitget close was persisting the wrong number because
 * `UpdateRemainingClosingDataJob::extractClosingPriceFromTrades()` is
 * Binance-shaped — it expects `side` to flip on close (LONG closed by SELL).
 *
 * Bitget hedge-mode reports `side: "buy"` for ALL LONG fills regardless of
 * open/close — the open/close discriminator is `tradeSide: "open"|"close"`.
 * Same for SHORT (always `side: "sell"`). So the matcher never matched,
 * the extractor fell through to its "most recent trade" fallback (which
 * uses `end($trades)` — the oldest fill in Bitget's newest-first response
 * window), and persisted whatever unrelated old fill was at the bottom of
 * the list.
 *
 * Two-part fix in `Bitget\MapsAccountQueryTrades::resolveQueryTradeResponse`:
 *   1. Flip `side` on close fills (`tradeSide=close`) so the cross-exchange
 *      extractor's `side === closeSide` check works.
 *   2. Reverse the fill list to oldest-first order, matching Binance's
 *      response convention. The extractor does its own `array_reverse`
 *      assuming oldest-first input — Bitget returning newest-first
 *      double-reversed and broke the iteration order even when sides
 *      did match.
 */
function bitgetTradesResponse(array $fills): Response
{
    return new Response(
        200,
        ['Content-Type' => 'application/json'],
        (string) json_encode([
            'code' => '00000',
            'msg' => 'success',
            'data' => ['fillList' => $fills, 'endId' => '0'],
        ]),
    );
}

it('flips side on LONG close fills (buy → sell) so the cross-exchange extractor matches', function (): void {
    $mapper = new BitgetApiDataMapper;

    // Bitget returns NEWEST-first — close (later cTime) appears first in
    // the response, then open (earlier cTime). Fixture mirrors reality.
    $response = bitgetTradesResponse([
        [
            'tradeId' => 'close-1',
            'orderId' => 'order-close',
            'price' => '0.3596',
            'baseVolume' => '86.9',
            'side' => 'buy',
            'tradeSide' => 'close',
            'cTime' => '1730000600000',
        ],
        [
            'tradeId' => 'open-1',
            'orderId' => 'order-open',
            'price' => '0.3584',
            'baseVolume' => '86.9',
            'side' => 'buy',
            'tradeSide' => 'open',
            'cTime' => '1730000000000',
        ],
    ]);

    $fills = $mapper->resolveQueryTradeResponse($response);

    // Mapper reverses to oldest-first: open at [0], close at [1].
    expect($fills[0]['tradeSide'])->toBe('open');
    expect($fills[0]['side'])->toBe('buy');

    // Close fill on a LONG must now read as 'sell' so
    // `extractClosingPriceFromTrades` matches `closeSide=SELL`.
    expect($fills[1]['tradeSide'])->toBe('close');
    expect($fills[1]['side'])->toBe('sell');
});

it('flips side on SHORT close fills (sell → buy)', function (): void {
    $mapper = new BitgetApiDataMapper;

    // Newest-first input (Bitget convention).
    $response = bitgetTradesResponse([
        [
            'tradeId' => 'short-close',
            'orderId' => 'short-close-id',
            'price' => '0.21450',
            'baseVolume' => '108',
            'side' => 'sell',
            'tradeSide' => 'close',
            'cTime' => '1730000600000',
        ],
        [
            'tradeId' => 'short-open',
            'orderId' => 'short-open-id',
            'price' => '0.21530',
            'baseVolume' => '108',
            'side' => 'sell',
            'tradeSide' => 'open',
            'cTime' => '1730000000000',
        ],
    ]);

    $fills = $mapper->resolveQueryTradeResponse($response);

    expect($fills[0]['tradeSide'])->toBe('open');
    expect($fills[0]['side'])->toBe('sell');

    // Close on a SHORT must read as 'buy' so closeSide=BUY matches.
    expect($fills[1]['tradeSide'])->toBe('close');
    expect($fills[1]['side'])->toBe('buy');
});

it('returns fills in oldest-first order (Binance convention) regardless of Bitget input order', function (): void {
    $mapper = new BitgetApiDataMapper;

    // Bitget returns newest-first.
    $response = bitgetTradesResponse([
        ['tradeId' => 'newest', 'price' => '3', 'side' => 'buy', 'tradeSide' => 'close', 'cTime' => '1730000900000', 'baseVolume' => '1'],
        ['tradeId' => 'middle', 'price' => '2', 'side' => 'buy', 'tradeSide' => 'open', 'cTime' => '1730000600000', 'baseVolume' => '1'],
        ['tradeId' => 'oldest', 'price' => '1', 'side' => 'buy', 'tradeSide' => 'open', 'cTime' => '1730000000000', 'baseVolume' => '1'],
    ]);

    $fills = $mapper->resolveQueryTradeResponse($response);

    expect($fills[0]['tradeId'])->toBe(
        'oldest',
        'Bitget returns newest-first; mapper must reverse to oldest-first '
        .'so the cross-exchange extractor (which does its own array_reverse '
        .'expecting oldest-first input) iterates from newest correctly.'
    );
    expect($fills[1]['tradeId'])->toBe('middle');
    expect($fills[2]['tradeId'])->toBe('newest');
});

it('does not modify side on open fills', function (): void {
    $mapper = new BitgetApiDataMapper;

    $response = bitgetTradesResponse([
        ['tradeId' => 'long-open', 'price' => '1', 'side' => 'buy', 'tradeSide' => 'open', 'cTime' => '1', 'baseVolume' => '1'],
        ['tradeId' => 'short-open', 'price' => '1', 'side' => 'sell', 'tradeSide' => 'open', 'cTime' => '2', 'baseVolume' => '1'],
    ]);

    $fills = $mapper->resolveQueryTradeResponse($response);

    // After reverse, short-open is first (newer cTime=2), long-open second.
    expect($fills[0]['side'])->toBe('sell');
    expect($fills[1]['side'])->toBe('buy');
});

it('handles missing tradeSide gracefully (defensive: leaves side unchanged)', function (): void {
    $mapper = new BitgetApiDataMapper;

    $response = bitgetTradesResponse([
        ['tradeId' => 'no-tradeside', 'price' => '1', 'side' => 'buy', 'cTime' => '1', 'baseVolume' => '1'],
    ]);

    $fills = $mapper->resolveQueryTradeResponse($response);

    expect($fills[0]['side'])->toBe(
        'buy',
        'When tradeSide is absent we cannot tell open from close — leave side as-is.'
    );
});

it('preserves all other fields verbatim', function (): void {
    $mapper = new BitgetApiDataMapper;

    $response = bitgetTradesResponse([
        [
            'tradeId' => 'preserved',
            'symbol' => 'SFPUSDT',
            'orderId' => 'order-xyz',
            'clientOid' => 'client-xyz',
            'price' => '0.3596',
            'baseVolume' => '86.9',
            'side' => 'buy',
            'tradeSide' => 'close',
            'profit' => '0.026',
            'cTime' => '1730000600000',
        ],
    ]);

    $fills = $mapper->resolveQueryTradeResponse($response);

    expect($fills[0]['tradeId'])->toBe('preserved');
    expect($fills[0]['symbol'])->toBe('SFPUSDT');
    expect($fills[0]['orderId'])->toBe('order-xyz');
    expect($fills[0]['clientOid'])->toBe('client-xyz');
    expect($fills[0]['price'])->toBe('0.3596');
    expect($fills[0]['baseVolume'])->toBe('86.9');
    expect($fills[0]['profit'])->toBe('0.026');
    expect($fills[0]['cTime'])->toBe('1730000600000');
    // Only side is normalized.
    expect($fills[0]['side'])->toBe('sell');
    expect($fills[0]['tradeSide'])->toBe('close');
});

it('returns empty array when fillList is empty or absent', function (): void {
    $mapper = new BitgetApiDataMapper;

    $emptyResponse = bitgetTradesResponse([]);
    expect($mapper->resolveQueryTradeResponse($emptyResponse))->toBe([]);

    $absentResponse = new Response(200, [], (string) json_encode(['code' => '00000', 'data' => []]));
    expect($mapper->resolveQueryTradeResponse($absentResponse))->toBe([]);
});
