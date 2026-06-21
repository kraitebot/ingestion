<?php

declare(strict_types=1);

use Kraite\Core\Support\ApiExceptionHandlers\BinanceExceptionHandler;
use Kraite\Core\Support\ApiExceptionHandlers\BitgetExceptionHandler;
use Kraite\Core\Support\ApiExceptionHandlers\BybitExceptionHandler;
use Kraite\Core\Support\ApiExceptionHandlers\KucoinExceptionHandler;
use Tests\Support\ResponseException;

/**
 * Verifies the reactive "symbol delisted" classifier that fires when an
 * exchange tells us a symbol no longer exists during a runtime request
 * (e.g. FetchKlinesJob). Proactive detection — via delivery_ts_ms changes
 * during the market-data refresh — lives in the per-exchange TradingMapper.
 */
uses()->group('unit', 'exception-handlers', 'symbol-delisted');

it('Binance: -1121 Invalid symbol is flagged as delisted', function (): void {
    $handler = new BinanceExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::binanceSymbolDelisted()))->toBeTrue();
});

it('Binance: unrelated 400 errors are not flagged as delisted', function (): void {
    $handler = new BinanceExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::binanceWafLimit()))->toBeFalse();
    expect($handler->isSymbolDelisted(ResponseException::binanceIgnorableMarginType()))->toBeFalse();
});

it('Bybit: retCode 10001 with "Not supported symbols" is flagged as delisted', function (): void {
    $handler = new BybitExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::bybitSymbolDelisted()))->toBeTrue();
});

it('Bybit: retCode 10001 without symbol keywords is not flagged as delisted', function (): void {
    $handler = new BybitExceptionHandler;

    $genericParamError = ResponseException::bybit(200, 10001, 'orderLinkId is required');
    expect($handler->isSymbolDelisted($genericParamError))->toBeFalse();
});

it('Bybit: unrelated retCodes are not flagged as delisted', function (): void {
    $handler = new BybitExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::bybitIpRateLimited()))->toBeFalse();
    expect($handler->isSymbolDelisted(ResponseException::bybitInvalidSignature()))->toBeFalse();
});

it('KuCoin: code 200003 "symbol parameter is invalid" is flagged as delisted', function (): void {
    $handler = new KucoinExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::kucoinSymbolDelisted()))->toBeTrue();
});

it('KuCoin: unrelated codes are not flagged as delisted', function (): void {
    $handler = new KucoinExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::kucoinInvalidParameter()))->toBeFalse();
    expect($handler->isSymbolDelisted(ResponseException::kucoinOrderNotExist()))->toBeFalse();
});

it('BitGet: code 40309 "contract has been removed" is flagged as delisted', function (): void {
    $handler = new BitgetExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::bitgetSymbolDelisted()))->toBeTrue();
});

it('BitGet: code 40034 "Parameter {symbol} does not exist" is flagged as delisted', function (): void {
    // The kline / market-data endpoints answer with 40034 once a contract is
    // gone (the 2026-06 TON→GRAM rebrand: BitGet pulled TONUSDT and every
    // kline fetch returned 40034). Without this, FetchKlinesJob's reactive
    // self-heal never fires and the dead symbol fails every refresh cycle.
    $handler = new BitgetExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::bitgetSymbolNotFound40034()))->toBeTrue();
});

it('BitGet: unrelated codes are not flagged as delisted', function (): void {
    $handler = new BitgetExceptionHandler;

    // 40808 "Parameter verification exception" is a malformed/invalid param
    // (e.g. a bad granularity) — a request-shape bug, NOT a delisting. It must
    // stay false so a regression can never mass-delist the universe; it is the
    // exact code 40034 is deliberately distinguished from.
    expect($handler->isSymbolDelisted(ResponseException::bitgetParameterVerificationException()))->toBeFalse();
    expect($handler->isSymbolDelisted(ResponseException::bitgetSystemMaintenance()))->toBeFalse();
});
