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

it('Binance: -1121 Invalid symbol is flagged as delisted', function () {
    $handler = new BinanceExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::binanceSymbolDelisted()))->toBeTrue();
});

it('Binance: unrelated 400 errors are not flagged as delisted', function () {
    $handler = new BinanceExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::binanceWafLimit()))->toBeFalse();
    expect($handler->isSymbolDelisted(ResponseException::binanceIgnorableMarginType()))->toBeFalse();
});

it('Bybit: retCode 10001 with "Not supported symbols" is flagged as delisted', function () {
    $handler = new BybitExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::bybitSymbolDelisted()))->toBeTrue();
});

it('Bybit: retCode 10001 without symbol keywords is not flagged as delisted', function () {
    $handler = new BybitExceptionHandler;

    $genericParamError = ResponseException::bybit(200, 10001, 'orderLinkId is required');
    expect($handler->isSymbolDelisted($genericParamError))->toBeFalse();
});

it('Bybit: unrelated retCodes are not flagged as delisted', function () {
    $handler = new BybitExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::bybitIpRateLimited()))->toBeFalse();
    expect($handler->isSymbolDelisted(ResponseException::bybitInvalidSignature()))->toBeFalse();
});

it('KuCoin: code 200003 "symbol parameter is invalid" is flagged as delisted', function () {
    $handler = new KucoinExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::kucoinSymbolDelisted()))->toBeTrue();
});

it('KuCoin: unrelated codes are not flagged as delisted', function () {
    $handler = new KucoinExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::kucoinInvalidParameter()))->toBeFalse();
    expect($handler->isSymbolDelisted(ResponseException::kucoinOrderNotExist()))->toBeFalse();
});

it('BitGet: code 40309 "contract has been removed" is flagged as delisted', function () {
    $handler = new BitgetExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::bitgetSymbolDelisted()))->toBeTrue();
});

it('BitGet: unrelated codes are not flagged as delisted', function () {
    $handler = new BitgetExceptionHandler;

    expect($handler->isSymbolDelisted(ResponseException::bitgetParameterVerificationException()))->toBeFalse();
    expect($handler->isSymbolDelisted(ResponseException::bitgetSystemMaintenance()))->toBeFalse();
});
