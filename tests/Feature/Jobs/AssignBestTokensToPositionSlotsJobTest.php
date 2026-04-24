<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Kraite\Core\Jobs\Models\Account\AssignBestTokensToPositionSlotsJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite as KraiteSettings;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Models\TradeConfiguration;
use StepDispatcher\Models\StepsDispatcher;

/**
 * Helper to create a test account with all required relationships
 */
function createAccountForSlotTest(string $suffix = '', int $maxLongs = 2, int $maxShorts = 1): Account
{
    $apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        [
            'name' => 'Binance',
            'is_exchange' => true,
        ]
    );

    // Timeframes used to live per-exchange on `api_systems`; now on the
    // kraite singleton. Seed the 5-timeframe fixture this suite exercises.
    KraiteSettings::updateOrCreate(
        ['id' => 1],
        ['timeframes' => ['5m', '1h', '4h', '12h', '1d']]
    );

    // Create ISOLATED test quote to avoid collision with seeded symbols
    $testQuoteCanonical = 'SLOTTEST'.fake()->randomNumber(6);

    $tradeConfig = TradeConfiguration::firstOrCreate(
        ['is_default' => true],
        [
            'canonical' => 'default',
            'description' => 'Default configuration',
            'least_timeframe_index_to_change_indicator' => 3,
            'fast_trade_position_duration_seconds' => 600,
            'fast_trade_position_closed_age_seconds' => 3600,
            'disable_exchange_symbol_from_negative_pnl_position' => false,
        ]
    );

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'trade_configuration_id' => $tradeConfig->id,
        'trading_quote' => $testQuoteCanonical,
        'can_trade' => true,
        'total_positions_long' => $maxLongs,
        'total_positions_short' => $maxShorts,
    ]);
}

/**
 * Helper to create an exchange symbol with complete correlation/elasticity data
 */
function createExchangeSymbolForSlotTest(
    #[SensitiveParameter] string $token,
    string $direction,
    int $apiSystemId,
    string $quote
): ExchangeSymbol {
    $symbol = Symbol::factory()->create(['token' => $token]);

    return ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => $quote,
        'symbol_id' => $symbol->id,
        'api_system_id' => $apiSystemId,
        'is_manually_enabled' => true,
        'overlaps_with_binance' => true, // Required for tradeable() scope
        'is_marked_for_delisting' => false, // Required for tradeable() scope
        'has_no_indicator_data' => false, // Required for tradeable() scope
        'has_price_trend_misalignment' => false, // Required for tradeable() scope
        'has_early_direction_change' => false, // Required for tradeable() scope
        'has_invalid_indicator_direction' => false, // Required for tradeable() scope
        'api_statuses' => [
            'cmc_api_called' => true,
            'taapi_verified' => true,
            'has_taapi_data' => true, // Required for tradeable() scope
        ],
        'direction' => $direction,
        'indicators_timeframe' => '1h',
        'min_notional' => 10.0,
        'tick_size' => 0.0001,
        'price_precision' => 4,
        'quantity_precision' => 2,
        'leverage_brackets' => [['bracket' => 1, 'initialLeverage' => 50, 'notionalCap' => 100000, 'maintMarginRatio' => 0.01]], // Required for tradeable() scope
        'btc_correlation_rolling' => ['1h' => 0.5],
        'btc_correlation_pearson' => ['1h' => 0.5],
        'btc_correlation_spearman' => ['1h' => 0.5],
        'btc_elasticity_long' => ['1h' => 1.0],
        'btc_elasticity_short' => ['1h' => -1.0],
    ]);
}

/**
 * Helper to create BTC exchange symbol
 */
function createBtcForSlotTest(string $direction, int $apiSystemId, string $quote): ExchangeSymbol
{
    $btcSymbol = Symbol::firstOrCreate(
        ['token' => 'BTC'],
        ['name' => 'Bitcoin']
    );

    return ExchangeSymbol::updateOrCreate(
        [
            'token' => 'BTC',
            'quote' => $quote,
            'api_system_id' => $apiSystemId,
        ],
        [
            'symbol_id' => $btcSymbol->id,
            'is_manually_enabled' => true,
            'overlaps_with_binance' => true, // Required for tradeable() scope
            'is_marked_for_delisting' => false, // Required for tradeable() scope
            'has_no_indicator_data' => false, // Required for tradeable() scope
            'has_price_trend_misalignment' => false, // Required for tradeable() scope
            'has_early_direction_change' => false, // Required for tradeable() scope
            'has_invalid_indicator_direction' => false, // Required for tradeable() scope
            'api_statuses' => [
                'cmc_api_called' => true,
                'taapi_verified' => true,
                'has_taapi_data' => true, // Required for tradeable() scope
            ],
            'direction' => $direction,
            'indicators_timeframe' => '1h',
            'min_notional' => 10.0,
            'tick_size' => 0.01,
            'price_precision' => 2,
            'quantity_precision' => 3,
            'leverage_brackets' => [['bracket' => 1, 'initialLeverage' => 125, 'notionalCap' => 1000000, 'maintMarginRatio' => 0.004]], // Required for tradeable() scope
        ]
    );
}

/**
 * Helper to store exchange positions in api_snapshots (Binance format)
 */
function storeExchangePositions(Account $account, array $positions): void
{
    $formattedPositions = [];

    foreach ($positions as $position) {
        $key = $position['symbol'].':'.$position['positionSide'];
        $formattedPositions[$key] = [
            'symbol' => $position['symbol'],
            'positionSide' => $position['positionSide'],
            'positionAmt' => $position['positionAmt'] ?? '100',
            'entryPrice' => $position['entryPrice'] ?? '1.0',
        ];
    }

    ApiSnapshot::storeFor($account, 'account-positions', $formattedPositions);
}

/**
 * Helper to store open orders in api_snapshots (Binance format)
 */
function storeOpenOrders(Account $account, array $orders): void
{
    $formattedOrders = [];

    foreach ($orders as $order) {
        $formattedOrders[] = [
            'orderId' => $order['orderId'] ?? fake()->randomNumber(9),
            'symbol' => $order['symbol'],
            'status' => 'NEW',
            'price' => $order['price'] ?? '1.0',
            'origQty' => $order['origQty'] ?? '10',
            'side' => $order['side'] ?? 'BUY',
            'positionSide' => $order['positionSide'] ?? 'LONG',
            'type' => $order['type'] ?? 'LIMIT',
        ];
    }

    ApiSnapshot::storeFor($account, 'account-open-orders', $formattedOrders);
}

beforeEach(function () {
    StepsDispatcher::updateOrCreate(['group' => 'alpha'], ['can_dispatch' => true]);
    StepsDispatcher::updateOrCreate(['group' => 'beta'], ['can_dispatch' => true]);

    Config::set('kraite.token_discovery.correlation_type', 'pearson');
    Config::set('kraite.token_discovery.btc_biased_restriction', true);
    Config::set('kraite.token_discovery.require_matching_correlation_sign', false);
    Config::set('kraite.correlation.btc_token', 'BTC');
});

/*
|--------------------------------------------------------------------------
| Slot Creation Tests - Exchange Position Detection
|--------------------------------------------------------------------------
|
| Tests that verify slot creation correctly accounts for positions
| already open on the exchange (from api_snapshots).
|
*/

test('creates no SHORT slots when exchange already has max SHORT positions', function () {
    // Account with max 2 LONGs, 1 SHORT
    $account = createAccountForSlotTest('short-max', maxLongs: 2, maxShorts: 1);

    // BTC is LONG (for token discovery)
    createBtcForSlotTest('LONG', $account->api_system_id, $account->trading_quote);

    // Create available tokens
    createExchangeSymbolForSlotTest('AVAIL1', 'LONG', $account->api_system_id, $account->trading_quote);
    createExchangeSymbolForSlotTest('AVAIL2', 'SHORT', $account->api_system_id, $account->trading_quote);

    // Store 1 SHORT position already open on exchange
    storeExchangePositions($account, [
        ['symbol' => 'CVCUSDT', 'positionSide' => 'SHORT', 'positionAmt' => '-322'],
    ]);

    // Empty open orders
    storeOpenOrders($account, []);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // Should have created LONG slots but NO SHORT slots
    expect($result['exchange_positions']['shorts'])->toBe(1);
    expect($result['available_slots']['shorts'])->toBe(0);
    expect($result['available_slots']['longs'])->toBe(2);

    // Verify only LONG positions were created
    $createdDirections = collect($result['created_positions'])->pluck('direction')->toArray();
    expect($createdDirections)->not->toContain('SHORT');
    expect($createdDirections)->toContain('LONG');
});

test('creates no LONG slots when exchange already has max LONG positions', function () {
    // Account with max 1 LONG, 2 SHORTs
    $account = createAccountForSlotTest('long-max', maxLongs: 1, maxShorts: 2);

    // BTC is SHORT (for token discovery to work with SHORT positions)
    createBtcForSlotTest('SHORT', $account->api_system_id, $account->trading_quote);

    // Create available tokens
    createExchangeSymbolForSlotTest('AVAIL3', 'LONG', $account->api_system_id, $account->trading_quote);
    createExchangeSymbolForSlotTest('AVAIL4', 'SHORT', $account->api_system_id, $account->trading_quote);

    // Store 1 LONG position already open on exchange
    storeExchangePositions($account, [
        ['symbol' => 'SWARMSUSDT', 'positionSide' => 'LONG', 'positionAmt' => '1044'],
    ]);

    storeOpenOrders($account, []);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // Should have created SHORT slots but NO LONG slots
    expect($result['exchange_positions']['longs'])->toBe(1);
    expect($result['available_slots']['longs'])->toBe(0);
    expect($result['available_slots']['shorts'])->toBe(2);

    // Verify only SHORT positions were created
    $createdDirections = collect($result['created_positions'])->pluck('direction')->toArray();
    expect($createdDirections)->not->toContain('LONG');
});

test('reduces available LONG slots based on exchange positions', function () {
    // Account with max 3 LONGs
    $account = createAccountForSlotTest('long-partial', maxLongs: 3, maxShorts: 0);

    createBtcForSlotTest('LONG', $account->api_system_id, $account->trading_quote);

    // Create multiple available tokens
    createExchangeSymbolForSlotTest('TOKEN1', 'LONG', $account->api_system_id, $account->trading_quote);
    createExchangeSymbolForSlotTest('TOKEN2', 'LONG', $account->api_system_id, $account->trading_quote);
    createExchangeSymbolForSlotTest('TOKEN3', 'LONG', $account->api_system_id, $account->trading_quote);

    // Store 2 LONG positions already open (leaving 1 slot available)
    storeExchangePositions($account, [
        ['symbol' => 'BTCUSDT', 'positionSide' => 'LONG', 'positionAmt' => '0.5'],
        ['symbol' => 'ETHUSDT', 'positionSide' => 'LONG', 'positionAmt' => '1.0'],
    ]);

    storeOpenOrders($account, []);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // Should only have 1 available LONG slot (3 max - 2 open = 1)
    expect($result['exchange_positions']['longs'])->toBe(2);
    expect($result['available_slots']['longs'])->toBe(1);
    expect($result['total_created'])->toBe(1);
});

test('reduces available SHORT slots based on exchange positions', function () {
    // Account with max 3 SHORTs
    $account = createAccountForSlotTest('short-partial', maxLongs: 0, maxShorts: 3);

    createBtcForSlotTest('SHORT', $account->api_system_id, $account->trading_quote);

    // Create multiple available tokens
    createExchangeSymbolForSlotTest('STOKEN1', 'SHORT', $account->api_system_id, $account->trading_quote);
    createExchangeSymbolForSlotTest('STOKEN2', 'SHORT', $account->api_system_id, $account->trading_quote);
    createExchangeSymbolForSlotTest('STOKEN3', 'SHORT', $account->api_system_id, $account->trading_quote);

    // Store 1 SHORT position already open (leaving 2 slots available)
    storeExchangePositions($account, [
        ['symbol' => 'XRPUSDT', 'positionSide' => 'SHORT', 'positionAmt' => '-500'],
    ]);

    storeOpenOrders($account, []);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // Should only have 2 available SHORT slots (3 max - 1 open = 2)
    expect($result['exchange_positions']['shorts'])->toBe(1);
    expect($result['available_slots']['shorts'])->toBe(2);
    expect($result['total_created'])->toBe(2);
});

test('creates no slots when exchange has all positions filled', function () {
    // Account with max 1 LONG, 1 SHORT
    $account = createAccountForSlotTest('all-filled', maxLongs: 1, maxShorts: 1);

    createBtcForSlotTest('LONG', $account->api_system_id, $account->trading_quote);

    createExchangeSymbolForSlotTest('UNUSED1', 'LONG', $account->api_system_id, $account->trading_quote);
    createExchangeSymbolForSlotTest('UNUSED2', 'SHORT', $account->api_system_id, $account->trading_quote);

    // Both LONG and SHORT positions already open
    storeExchangePositions($account, [
        ['symbol' => 'BTCUSDT', 'positionSide' => 'LONG', 'positionAmt' => '0.1'],
        ['symbol' => 'ETHUSDT', 'positionSide' => 'SHORT', 'positionAmt' => '-0.5'],
    ]);

    storeOpenOrders($account, []);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // No slots should be created
    expect($result['available_slots']['longs'])->toBe(0);
    expect($result['available_slots']['shorts'])->toBe(0);
    expect($result['total_created'])->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Open Orders Exclusion Tests
|--------------------------------------------------------------------------
|
| Tests that verify tokens with open orders on the exchange are excluded
| from being assigned to new position slots.
|
*/

test('excludes token with open order from assignment', function () {
    $account = createAccountForSlotTest('order-exclude', maxLongs: 1, maxShorts: 0);

    createBtcForSlotTest('LONG', $account->api_system_id, $account->trading_quote);

    // Create two tokens - one will have an open order
    $tokenWithOrder = createExchangeSymbolForSlotTest('HASORDER', 'LONG', $account->api_system_id, $account->trading_quote);
    $tokenWithOrder->update([
        'btc_correlation_pearson' => ['1h' => 0.95],
        'btc_elasticity_long' => ['1h' => 2.0],
    ]);

    $availableToken = createExchangeSymbolForSlotTest('NOORDER', 'LONG', $account->api_system_id, $account->trading_quote);

    // No open positions
    storeExchangePositions($account, []);

    // Open order on the best-scoring token
    storeOpenOrders($account, [
        ['symbol' => $tokenWithOrder->parsed_trading_pair, 'positionSide' => 'LONG', 'side' => 'BUY'],
    ]);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // Should assign the available token, not the one with open order
    expect($result['assigned_count'])->toBe(1);
    expect($result['assigned_tokens'])->toContain('NOORDER');
    expect($result['assigned_tokens'])->not->toContain('HASORDER');

    // Verify the position was assigned to the correct token
    $position = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->whereNotNull('exchange_symbol_id')
        ->first();

    expect($position->exchange_symbol_id)->toBe($availableToken->id);
});

test('excludes multiple tokens with open orders', function () {
    $account = createAccountForSlotTest('multi-order', maxLongs: 1, maxShorts: 0);

    createBtcForSlotTest('LONG', $account->api_system_id, $account->trading_quote);

    // Create three tokens - two will have open orders
    $token1 = createExchangeSymbolForSlotTest('ORDER1', 'LONG', $account->api_system_id, $account->trading_quote);
    $token1->update(['btc_correlation_pearson' => ['1h' => 0.99], 'btc_elasticity_long' => ['1h' => 3.0]]);

    $token2 = createExchangeSymbolForSlotTest('ORDER2', 'LONG', $account->api_system_id, $account->trading_quote);
    $token2->update(['btc_correlation_pearson' => ['1h' => 0.95], 'btc_elasticity_long' => ['1h' => 2.5]]);

    $availableToken = createExchangeSymbolForSlotTest('AVAILABLE', 'LONG', $account->api_system_id, $account->trading_quote);

    storeExchangePositions($account, []);

    // Open orders on both best-scoring tokens
    storeOpenOrders($account, [
        ['symbol' => $token1->parsed_trading_pair, 'positionSide' => 'LONG'],
        ['symbol' => $token2->parsed_trading_pair, 'positionSide' => 'LONG'],
    ]);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // Should only assign the available token
    expect($result['assigned_tokens'])->toContain('AVAILABLE');
    expect($result['assigned_tokens'])->not->toContain('ORDER1');
    expect($result['assigned_tokens'])->not->toContain('ORDER2');
});

test('allows token assignment when no open orders exist', function () {
    $account = createAccountForSlotTest('no-orders', maxLongs: 1, maxShorts: 0);

    createBtcForSlotTest('LONG', $account->api_system_id, $account->trading_quote);

    $bestToken = createExchangeSymbolForSlotTest('BEST', 'LONG', $account->api_system_id, $account->trading_quote);
    $bestToken->update(['btc_correlation_pearson' => ['1h' => 0.95], 'btc_elasticity_long' => ['1h' => 2.0]]);

    createExchangeSymbolForSlotTest('SECOND', 'LONG', $account->api_system_id, $account->trading_quote);

    storeExchangePositions($account, []);
    storeOpenOrders($account, []); // Empty - no open orders

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // Should assign the best token
    expect($result['assigned_tokens'])->toContain('BEST');
});

/*
|--------------------------------------------------------------------------
| Combined Scenario Tests - Positions + Orders
|--------------------------------------------------------------------------
|
| Tests that verify the workflow correctly handles both open positions
| (affecting slot creation) and open orders (affecting token assignment).
|
*/

test('handles combination of open position and open order', function () {
    // Account with max 2 LONGs, 1 SHORT
    $account = createAccountForSlotTest('combined', maxLongs: 2, maxShorts: 1);

    createBtcForSlotTest('LONG', $account->api_system_id, $account->trading_quote);

    // Create LONG tokens
    $longToken1 = createExchangeSymbolForSlotTest('LONG1', 'LONG', $account->api_system_id, $account->trading_quote);
    $longToken1->update(['btc_correlation_pearson' => ['1h' => 0.9], 'btc_elasticity_long' => ['1h' => 2.0]]);

    $longToken2 = createExchangeSymbolForSlotTest('LONG2', 'LONG', $account->api_system_id, $account->trading_quote);
    $longToken2->update(['btc_correlation_pearson' => ['1h' => 0.7], 'btc_elasticity_long' => ['1h' => 1.5]]);

    // Create SHORT token (won't be used - no SHORT slots)
    createExchangeSymbolForSlotTest('SHORT1', 'SHORT', $account->api_system_id, $account->trading_quote);

    // 1 LONG position open on exchange (leaves 1 LONG slot)
    // 1 SHORT position open on exchange (leaves 0 SHORT slots)
    storeExchangePositions($account, [
        ['symbol' => 'SWARMSUSDT', 'positionSide' => 'LONG', 'positionAmt' => '1000'],
        ['symbol' => 'CVCUSDT', 'positionSide' => 'SHORT', 'positionAmt' => '-500'],
    ]);

    // Open order on the best LONG token
    storeOpenOrders($account, [
        ['symbol' => $longToken1->parsed_trading_pair, 'positionSide' => 'LONG'],
    ]);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // Slot creation: 1 LONG available, 0 SHORTs available
    expect($result['available_slots']['longs'])->toBe(1);
    expect($result['available_slots']['shorts'])->toBe(0);
    expect($result['total_created'])->toBe(1);

    // Token assignment: LONG1 excluded (open order), should use LONG2
    expect($result['assigned_count'])->toBe(1);
    expect($result['assigned_tokens'])->toContain('LONG2');
    expect($result['assigned_tokens'])->not->toContain('LONG1');
    expect($result['assigned_tokens'])->not->toContain('SHORT1');
});

test('real scenario: SWARM LONG open, PIEVERSEUSDT limit order, CVC SHORT open', function () {
    // This replicates the exact scenario tested manually
    $account = createAccountForSlotTest('real-scenario', maxLongs: 2, maxShorts: 1);

    createBtcForSlotTest('LONG', $account->api_system_id, $account->trading_quote);

    // Create tokens matching real scenario
    $swarm = createExchangeSymbolForSlotTest('SWARMS', 'LONG', $account->api_system_id, $account->trading_quote);
    $swarm->update(['btc_correlation_pearson' => ['1h' => 0.9], 'btc_elasticity_long' => ['1h' => 2.0]]);

    $pieverseToken = createExchangeSymbolForSlotTest('PIEVERSERST', 'LONG', $account->api_system_id, $account->trading_quote);
    $pieverseToken->update(['btc_correlation_pearson' => ['1h' => 0.85], 'btc_elasticity_long' => ['1h' => 1.8]]);

    $fhe = createExchangeSymbolForSlotTest('FHERST', 'LONG', $account->api_system_id, $account->trading_quote);
    $fhe->update(['btc_correlation_pearson' => ['1h' => 0.6], 'btc_elasticity_long' => ['1h' => 1.0]]);

    // Open positions on exchange
    storeExchangePositions($account, [
        ['symbol' => $swarm->parsed_trading_pair, 'positionSide' => 'LONG', 'positionAmt' => '1044'],
        ['symbol' => 'CVCUSDT', 'positionSide' => 'SHORT', 'positionAmt' => '-322'],
    ]);

    // Open order on PIEVERSEUSDT
    storeOpenOrders($account, [
        ['symbol' => $pieverseToken->parsed_trading_pair, 'positionSide' => 'LONG', 'side' => 'BUY', 'type' => 'LIMIT'],
    ]);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // Slot creation: 1 LONG open → 1 LONG available, 1 SHORT open → 0 SHORTs available
    expect($result['exchange_positions']['longs'])->toBe(1);
    expect($result['exchange_positions']['shorts'])->toBe(1);
    expect($result['available_slots']['longs'])->toBe(1);
    expect($result['available_slots']['shorts'])->toBe(0);
    expect($result['total_created'])->toBe(1);

    // Token assignment: SWARMS excluded (open position), PIEVERSEUSDT excluded (open order)
    // Should assign FHERST
    expect($result['assigned_count'])->toBe(1);
    expect($result['assigned_tokens'])->toContain('FHERST');
    expect($result['assigned_tokens'])->not->toContain('SWARMS');
    expect($result['assigned_tokens'])->not->toContain('PIEVERSERST');
});

/*
|--------------------------------------------------------------------------
| Edge Cases and Boundary Tests
|--------------------------------------------------------------------------
*/

test('handles empty api_snapshots gracefully', function () {
    $account = createAccountForSlotTest('empty-snapshots', maxLongs: 1, maxShorts: 1);

    createBtcForSlotTest('LONG', $account->api_system_id, $account->trading_quote);

    createExchangeSymbolForSlotTest('TOKEN', 'LONG', $account->api_system_id, $account->trading_quote);

    // Don't store any snapshots (simulating first run or missing data)
    // The job should handle null/empty snapshots gracefully

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // Should create slots based on max (no exchange positions)
    expect($result['exchange_positions']['longs'])->toBe(0);
    expect($result['exchange_positions']['shorts'])->toBe(0);
    expect($result['available_slots']['longs'])->toBe(1);
    expect($result['available_slots']['shorts'])->toBe(1);
});

test('uses MAX of exchange and DB positions for conservative calculation', function () {
    // Account with max 3 LONGs
    $account = createAccountForSlotTest('max-calc', maxLongs: 3, maxShorts: 0);

    createBtcForSlotTest('LONG', $account->api_system_id, $account->trading_quote);

    createExchangeSymbolForSlotTest('T1', 'LONG', $account->api_system_id, $account->trading_quote);
    createExchangeSymbolForSlotTest('T2', 'LONG', $account->api_system_id, $account->trading_quote);
    createExchangeSymbolForSlotTest('T3', 'LONG', $account->api_system_id, $account->trading_quote);

    // 1 position in exchange snapshot
    storeExchangePositions($account, [
        ['symbol' => 'BTCUSDT', 'positionSide' => 'LONG', 'positionAmt' => '0.5'],
    ]);

    // 2 positions in database (more than exchange) - use 'active' status (valid opened status)
    Position::factory()->count(2)->create([
        'account_id' => $account->id,
        'status' => 'active',
        'direction' => 'LONG',
    ]);

    storeOpenOrders($account, []);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // Should use MAX(1 exchange, 2 db) = 2 positions
    // Available = 3 max - 2 = 1
    expect($result['available_slots']['longs'])->toBe(1);
});

test('deletes position slots that could not be assigned a token', function () {
    // Use btc_biased_restriction=false to avoid needing BTC direction
    Config::set('kraite.token_discovery.btc_biased_restriction', false);

    $account = createAccountForSlotTest('delete-unassigned', maxLongs: 2, maxShorts: 0);

    // Create BTC without direction (won't be assignable, just for reference)
    $btcSymbol = Symbol::firstOrCreate(['token' => 'BTC'], ['name' => 'Bitcoin']);
    ExchangeSymbol::updateOrCreate(
        [
            'token' => 'BTC',
            'quote' => $account->trading_quote,
            'api_system_id' => $account->api_system_id,
        ],
        [
            'symbol_id' => $btcSymbol->id,
            'is_manually_enabled' => true,
            'direction' => null, // No direction = not assignable
            'indicators_timeframe' => null,
            'min_notional' => 10.0,
            'tick_size' => 0.01,
            'price_precision' => 2,
            'quantity_precision' => 3,
        ]
    );

    // Only 1 LONG token available but 2 slots will be created
    createExchangeSymbolForSlotTest('ONLYONE', 'LONG', $account->api_system_id, $account->trading_quote);

    storeExchangePositions($account, []);
    storeOpenOrders($account, []);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // 2 slots created, 1 assigned
    expect($result['total_created'])->toBe(2);
    expect($result['assigned_count'])->toBe(1);

    // The unassigned slot is deleted by assignBestTokenToNewPositions() internally
    // Verify only 1 position remains (the assigned one)
    $remainingPositions = Position::where('account_id', $account->id)->count();
    expect($remainingPositions)->toBe(1);

    // Verify the remaining position has a token assigned
    $position = Position::where('account_id', $account->id)->first();
    expect($position->exchange_symbol_id)->not->toBeNull();
});

test('stops workflow when no slots created', function () {
    $account = createAccountForSlotTest('no-slots', maxLongs: 1, maxShorts: 1);

    createBtcForSlotTest('LONG', $account->api_system_id, $account->trading_quote);

    createExchangeSymbolForSlotTest('UNUSED', 'LONG', $account->api_system_id, $account->trading_quote);

    // All positions filled on exchange
    storeExchangePositions($account, [
        ['symbol' => 'BTCUSDT', 'positionSide' => 'LONG', 'positionAmt' => '0.5'],
        ['symbol' => 'ETHUSDT', 'positionSide' => 'SHORT', 'positionAmt' => '-1.0'],
    ]);

    storeOpenOrders($account, []);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $job->compute();

    // The complete() hook should call stopJob() when totalCreated === 0
    // We can't directly test stopJob was called, but we verify state
    expect($job->totalCreated)->toBe(0);
});

test('stops workflow when no tokens assigned', function () {
    $account = createAccountForSlotTest('no-tokens', maxLongs: 1, maxShorts: 0);

    createBtcForSlotTest('LONG', $account->api_system_id, $account->trading_quote);

    // No tokens available for assignment
    // (BTC exists but won't be assigned to itself)

    storeExchangePositions($account, []);
    storeOpenOrders($account, []);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $job->compute();

    // Slot created but no token assigned
    expect($job->totalCreated)->toBe(1);
    expect($job->assignedCount)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Bybit Format Support Tests
|--------------------------------------------------------------------------
|
| Tests that position counting works with Bybit's API format
| (side: Buy/Sell instead of positionSide: LONG/SHORT)
|
*/

test('counts Bybit format positions correctly (Buy = LONG)', function () {
    $account = createAccountForSlotTest('bybit-long', maxLongs: 2, maxShorts: 0);

    createBtcForSlotTest('LONG', $account->api_system_id, $account->trading_quote);

    createExchangeSymbolForSlotTest('BYBITTOKEN', 'LONG', $account->api_system_id, $account->trading_quote);

    // Bybit uses 'side' with 'Buy'/'Sell' instead of 'positionSide' with 'LONG'/'SHORT'
    ApiSnapshot::storeFor($account, 'account-positions', [
        'BTCUSDT:BUY' => [
            'symbol' => 'BTCUSDT',
            'side' => 'Buy', // Bybit format
            'size' => '0.5',
        ],
    ]);

    storeOpenOrders($account, []);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // Should count Buy as LONG
    expect($result['exchange_positions']['longs'])->toBe(1);
    expect($result['available_slots']['longs'])->toBe(1);
});

test('counts Bybit format positions correctly (Sell = SHORT)', function () {
    $account = createAccountForSlotTest('bybit-short', maxLongs: 0, maxShorts: 2);

    createBtcForSlotTest('SHORT', $account->api_system_id, $account->trading_quote);

    createExchangeSymbolForSlotTest('BYBITSHORT', 'SHORT', $account->api_system_id, $account->trading_quote);

    // Bybit uses 'side' with 'Sell' for SHORT
    ApiSnapshot::storeFor($account, 'account-positions', [
        'ETHUSDT:SELL' => [
            'symbol' => 'ETHUSDT',
            'side' => 'Sell', // Bybit format
            'size' => '1.0',
        ],
    ]);

    storeOpenOrders($account, []);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);
    $result = $job->compute();

    // Should count Sell as SHORT
    expect($result['exchange_positions']['shorts'])->toBe(1);
    expect($result['available_slots']['shorts'])->toBe(1);
});
