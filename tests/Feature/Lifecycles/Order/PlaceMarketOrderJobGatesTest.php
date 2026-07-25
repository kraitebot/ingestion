<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\PlaceMarketOrderJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\ExchangeSymbolPrice;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Subscription;
use Kraite\Core\Models\Symbol;

/**
 * Pin the PlaceMarketOrder gate.
 *
 *   - position.status MUST be 'opening' (the cascade's entry point).
 *     A regression that admits 'active' or 'syncing' ships as a
 *     duplicate market order on a position already mid-life — real-
 *     money exposure.
 *
 *   - margin must be set (PrepareData has run). Without margin the
 *     downstream qty calculation throws InvalidArgumentException
 *     against null.
 *
 *   - On retry, the gate must REUSE the existing MARKET row instead
 *     of letting a duplicate hit the exchange. The retry path tag
 *     in startOrFail() loads the existing order onto $this->
 *     marketOrder so computeApiable can short-circuit.
 */
function buildMarketReadyPosition(array $overrides = []): Position
{
    $position = Position::factory()->long()->create(array_merge([
        'status' => 'opening',
        'margin' => '50.00',
        'leverage' => 20,
        'total_limit_orders' => 4,
    ], $overrides));

    $subscription = Subscription::firstOrCreate(
        ['canonical' => 'market-order-gates'],
        ['name' => 'Market Order Gates', 'monthly_rate_usdt' => '75.0000', 'trial_days' => 7],
    );
    $position->account->user->forceFill([
        'subscription_id' => $subscription->id,
        'is_active' => true,
        'can_trade' => true,
        'wallet_balance_usdt' => '100.0000',
        'subscription_renews_at' => now()->addDays(30),
        'trial_started_at' => null,
        'subscription_paused_at' => null,
    ])->save();
    $position->account->forceFill([
        'is_active' => true,
        'can_trade' => true,
    ])->save();

    if ($subscription->max_accounts === 1) {
        $position->account->user->update(['active_account_id' => $position->account->id]);
    }

    return $position;
}

function attachMarketGateExchangeSymbol(Position $position, array $overrides): ExchangeSymbol
{
    $token = 'GATE'.Str::upper(Str::random(6));
    $apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        ['name' => 'Binance market gate'],
    );
    $position->account->forceFill(['api_system_id' => $apiSystem->id])->save();
    $position->account->unsetRelation('apiSystem');
    $symbol = Symbol::factory()->create(['token' => $token]);
    $exchangeSymbol = ExchangeSymbol::factory()->create(array_merge([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'token' => $token,
        'quote' => 'USDT',
    ], $overrides));

    $position->forceFill(['exchange_symbol_id' => $exchangeSymbol->id])->save();
    $position->setRelation('exchangeSymbol', $exchangeSymbol);

    return $exchangeSymbol;
}

it('passes when status=opening and margin is set', function (): void {
    $position = buildMarketReadyPosition();
    $job = new PlaceMarketOrderJob($position->id);

    expect($job->startOrStop())->toBeTrue()
        ->and($job->startOrFail())->toBeTrue();
});

it('stops before placing a market entry when account trading was disabled', function (): void {
    $position = buildMarketReadyPosition();
    $position->account->forceFill(['can_trade' => false])->save();

    expect((new PlaceMarketOrderJob($position->id))->startOrStop())->toBeFalse();
});

it('continues reconciling an already placed market entry after account trading is disabled', function (): void {
    $position = buildMarketReadyPosition();
    $position->account->forceFill(['can_trade' => false])->save();
    Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => 'already-placed-entry',
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'MARKET',
        'price' => '0.10',
        'quantity' => '100',
        'status' => 'NEW',
    ]);

    expect((new PlaceMarketOrderJob($position->id))->startOrStop())->toBeTrue();
});

it('refuses when position status is not opening (cascade entry guard)', function (string $nonOpening): void {
    $position = buildMarketReadyPosition(['status' => $nonOpening]);

    expect((new PlaceMarketOrderJob($position->id))->startOrFail())->toBeFalse();
})->with([
    'active' => ['active'],
    'syncing' => ['syncing'],
    'closing' => ['closing'],
    'closed' => ['closed'],
    'cancelled' => ['cancelled'],
    'failed' => ['failed'],
    'watching' => ['watching'],
    'new' => ['new'],
]);

it('refuses when margin is null (PrepareData has not run)', function (): void {
    $position = buildMarketReadyPosition(['margin' => null]);

    expect((new PlaceMarketOrderJob($position->id))->startOrFail())->toBeFalse();
});

it('on retry, loads the existing MARKET order onto $this->marketOrder (no duplicate placement)', function (): void {
    $position = buildMarketReadyPosition();

    $existing = Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'MARKET',
        'price' => '0.10',
        'quantity' => '100',
        'status' => 'FILLED',
    ]);

    $job = new PlaceMarketOrderJob($position->id);
    $job->startOrFail();

    expect($job->marketOrder)->not->toBeNull()
        ->and($job->marketOrder->id)->toBe($existing->id);
});

it('on first run (no existing MARKET), $this->marketOrder remains null after the gate', function (): void {
    $position = buildMarketReadyPosition();

    $job = new PlaceMarketOrderJob($position->id);
    $job->startOrFail();

    expect($job->marketOrder)->toBeNull();
});

it('refuses a market entry that fell below minimum notional after the pre-gate', function (): void {
    $position = buildMarketReadyPosition();
    $exchangeSymbol = attachMarketGateExchangeSymbol($position, [
        'min_notional' => '31.20',
        'price_precision' => 2,
        'quantity_precision' => 0,
        'tick_size' => '0.01',
        'min_price' => '0.01',
        'max_price' => '1000',
    ]);
    ExchangeSymbolPrice::updateOrCreate(
        ['exchange_symbol_id' => $exchangeSymbol->id],
        ['mark_price' => '10', 'mark_price_synced_at' => now()],
    );

    Http::fake(fn () => throw new RuntimeException('Exchange placement must not be reached.'));

    expect(fn () => (new PlaceMarketOrderJob($position->id))->computeApiable())
        ->toThrow(RuntimeException::class, 'Failed to calculate quantity');

    Http::assertNothingSent();
});

it('revalidates the full ladder against the latest price before placing market exposure', function (): void {
    $position = buildMarketReadyPosition();
    $exchangeSymbol = attachMarketGateExchangeSymbol($position, [
        'min_notional' => '20',
        'price_precision' => 2,
        'quantity_precision' => 0,
        'tick_size' => '0.01',
        'min_price' => '0.01',
        'max_price' => '1000',
        'percentage_gap_long' => '8.5',
        'limit_quantity_multipliers' => [0.5, 2, 2, 2],
    ]);
    ExchangeSymbolPrice::updateOrCreate(
        ['exchange_symbol_id' => $exchangeSymbol->id],
        ['mark_price' => '10', 'mark_price_synced_at' => now()],
    );

    Http::fake(fn () => throw new RuntimeException('Exchange placement must not be reached.'));

    expect(fn () => (new PlaceMarketOrderJob($position->id))->computeApiable())
        ->toThrow(RuntimeException::class, 'below min_notional');

    Http::assertNothingSent();
});

it('reports the stored order quantity when retrying placement of an existing local MARKET row', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget MARKET retry reporting',
    ]);
    $symbol = Symbol::factory()->create(['token' => 'RETRYREPORT']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'asset' => 'RETRYREPORTUSDT',
        'token' => 'RETRYREPORT',
        'quote' => 'USDT',
        'mark_price' => '10',
        'price_precision' => 2,
        'quantity_precision' => 3,
        'min_notional' => '5',
    ]);
    $account = Account::factory()->oneWayMode()->create([
        'api_system_id' => $apiSystem->id,
        'margin_mode' => 'isolated',
        'bitget_account_mode' => 'unified',
        'bitget_api_key' => 'RETRY_REPORT_KEY',
        'bitget_api_secret' => 'RETRY_REPORT_SECRET',
        'bitget_passphrase' => 'RETRY_REPORT_PASSPHRASE',
    ]);
    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'opening',
        'margin' => '50',
        'leverage' => 20,
        'total_limit_orders' => 4,
    ]);
    $existingOrder = Order::create([
        'position_id' => $position->id,
        'side' => 'BUY',
        'position_side' => 'LONG',
        'type' => 'MARKET',
        'quantity' => '1',
        'status' => 'NEW',
    ]);

    Http::fake(function (Request $request) {
        $body = json_decode($request->body(), true, flags: JSON_THROW_ON_ERROR);

        expect($body['qty'])->toBe('1');

        return Http::response([
            'code' => '00000',
            'data' => ['orderId' => 'RETRY-REPORT-ORDER'],
        ]);
    });

    $job = new PlaceMarketOrderJob($position->id);

    expect($job->startOrFail())->toBeTrue()
        ->and($job->marketOrder?->id)->toBe($existingOrder->id);

    $result = $job->computeApiable();

    expect($result['quantity'])->toBe('1')
        ->and($result['notional'])->toBe('9.99')
        ->and($result['margin'])->toBe('50.00000000')
        ->and($result['exchange_order_id'])->toBe('RETRY-REPORT-ORDER')
        ->and($existingOrder->fresh()->exchange_order_id)->toBe('RETRY-REPORT-ORDER');
});
