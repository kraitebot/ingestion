<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper;

function buildBinancePositionForTradesTest(): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => 'API3']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'API3',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
    ]);

    return Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'SHORT',
    ]);
}

it('sets options.limit to 5 on the trades query', function (): void {
    $position = buildBinancePositionForTradesTest();
    $mapper = new BinanceApiDataMapper;

    $properties = $mapper->prepareQueryTokenTradesProperties($position);

    expect($properties->get('options.limit'))->toBe('5');
});

it('includes the orderId in options when passed', function (): void {
    $position = buildBinancePositionForTradesTest();
    $mapper = new BinanceApiDataMapper;

    $properties = $mapper->prepareQueryTokenTradesProperties($position, '12345');

    expect($properties->get('options.orderId'))->toBe('12345');
    expect($properties->get('options.limit'))->toBe('5');
});

it('omits orderId when not passed but still carries the limit', function (): void {
    $position = buildBinancePositionForTradesTest();
    $mapper = new BinanceApiDataMapper;

    $properties = $mapper->prepareQueryTokenTradesProperties($position);

    expect($properties->get('options.orderId'))->toBeNull();
    expect($properties->get('options.limit'))->toBe('5');
});
