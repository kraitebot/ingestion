<?php

declare(strict_types=1);

use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\Apis\REST\BitgetApi;
use Kraite\Core\Support\ValueObjects\ApiCredentials;
use Kraite\Core\Support\ValueObjects\ApiProperties;

function bitgetApiWithoutImplicitProduct(): BitgetApi
{
    ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget API Context Test',
    ]);

    return new BitgetApi(ApiCredentials::make([
        'bitget_api_key' => 'TEST_KEY',
        'bitget_api_secret' => 'TEST_SECRET',
        'bitget_passphrase' => 'TEST_PASSPHRASE',
    ]));
}

it('rejects requests without an explicit supported futures product', function (string $method): void {
    $api = bitgetApiWithoutImplicitProduct();

    expect(fn () => $api->{$method}(new ApiProperties))
        ->toThrow(
            InvalidArgumentException::class,
            'Bitget futures productType is required. Resolve it from the account or exchange symbol quote.'
        );
})->with([
    'catalogue' => ['getExchangeInformation'],
    'klines' => ['getKlines'],
    'positions' => ['getPositions'],
    'balances' => ['getAccountBalance'],
    'open orders' => ['getCurrentOpenOrders'],
    'plan orders' => ['getPlanOrders'],
    'place order' => ['placeOrder'],
    'order detail' => ['getOrderDetail'],
    'cancel order' => ['cancelOrder'],
    'modify order' => ['modifyOrder'],
    'cancel all orders' => ['cancelAllOrders'],
    'trade fills' => ['accountTrades'],
    'mark price' => ['getSymbolPrice'],
    'set leverage' => ['setLeverage'],
    'set margin mode' => ['setMarginMode'],
    'leverage brackets' => ['getLeverageBrackets'],
    'place plan order' => ['placePlanOrder'],
    'plan order detail' => ['getPlanOrderDetail'],
    'plan order history' => ['getPlanOrderHistory'],
    'cancel plan order' => ['cancelPlanOrder'],
    'place position protection' => ['placePosTpsl'],
    'place protection order' => ['placeTpslOrder'],
    'modify protection order' => ['modifyTpslOrder'],
    'flash close' => ['flashClosePosition'],
    'position history' => ['historyPosition'],
]);

it('rejects unsupported futures products at the API boundary', function (): void {
    $api = bitgetApiWithoutImplicitProduct();
    $properties = ApiProperties::make(['options' => ['productType' => 'COIN-FUTURES']]);

    expect(fn () => $api->getPositions($properties))
        ->toThrow(
            InvalidArgumentException::class,
            'Unsupported Bitget futures productType [COIN-FUTURES]. Supported product types: USDT-FUTURES, USDC-FUTURES.'
        );
});
