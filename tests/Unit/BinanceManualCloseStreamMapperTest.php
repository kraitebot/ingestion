<?php

declare(strict_types=1);

use Kraite\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper;

it('preserves the fields required to prove a regular Binance close', function (): void {
    $event = (new BinanceApiDataMapper)->resolveUserDataStreamEvent([
        'e' => 'ORDER_TRADE_UPDATE',
        'E' => 1_780_000_000_000,
        'o' => [
            's' => 'BTCUSDT',
            'i' => 123,
            'c' => 'external-close',
            'S' => 'SELL',
            'ps' => 'LONG',
            'o' => 'MARKET',
            'X' => 'FILLED',
            'x' => 'TRADE',
            'R' => false,
        ],
    ]);

    expect($event->side)->toBe('SELL')
        ->and($event->positionSide)->toBe('LONG')
        ->and($event->executionType)->toBe('TRADE');
});

it('preserves the fields required to prove a Binance algo close', function (): void {
    $event = (new BinanceApiDataMapper)->resolveUserDataStreamEvent([
        'e' => 'ALGO_UPDATE',
        'E' => 1_780_000_000_000,
        'o' => [
            's' => 'BTCUSDT',
            'aid' => 456,
            'caid' => 'external-algo-close',
            'S' => 'BUY',
            'ps' => 'SHORT',
            'ot' => 'STOP_MARKET',
            'X' => 'FILLED',
            'cp' => true,
        ],
    ]);

    expect($event->side)->toBe('BUY')
        ->and($event->positionSide)->toBe('SHORT')
        ->and($event->closePosition)->toBeTrue();
});
