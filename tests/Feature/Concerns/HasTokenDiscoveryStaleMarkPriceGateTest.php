<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\ExchangeSymbolPrice;
use Kraite\Core\Models\Kraite as KraiteSettings;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Models\TradeConfiguration;

/**
 * Stale-mark-price freshness gate inside token discovery.
 *
 * When the price daemon stalls (Binance WS hiccup, daemon crash, frame
 * loss), every reader of `exchange_symbol_prices.mark_price` keeps
 * returning the last value the daemon wrote — silently. Token
 * discovery uses mark_price for the wrong-side-pivot check; sizing
 * uses it as the divisor turning notional intent into MARKET order
 * quantity. Acting on a 60-second-stale price across 200 accounts in
 * the same tick risks a wave of bad picks and off-target sizing.
 *
 * Contract: a token whose sidecar `mark_price_synced_at` is older
 * than `kraite.token_discovery.mark_price_max_age_seconds` (default
 * 30) MUST be filtered out of the token-assignment pool, even when
 * its score would otherwise win. This pin uses two candidates with
 * identical scoring inputs apart from sidecar age: the stale one
 * gets dropped, the fresh one wins by elimination.
 */
beforeEach(function (): void {
    KraiteSettings::updateOrCreate(
        ['id' => 1],
        ['timeframes' => ['1h', '4h', '12h', '1d']]
    );
});

function createAccountForStaleGateTest(): Account
{
    $apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        ['name' => 'Binance', 'is_exchange' => true]
    );

    KraiteSettings::updateOrCreate(
        ['id' => 1],
        ['timeframes' => ['5m', '1h', '4h', '12h', '1d']]
    );

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
        'trading_quote' => 'STALEQ'.fake()->randomNumber(6),
        'can_trade' => true,
        'total_positions_long' => 1,
        'total_positions_short' => 0,
    ]);
}

function makeTradeableSymbol(
    string $token,
    Account $account,
    array $correlation,
    array $elasticityLong,
    array $elasticityShort,
): ExchangeSymbol {
    $symbol = Symbol::factory()->create(['token' => $token]);

    return ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => $account->trading_quote,
        'symbol_id' => $symbol->id,
        'api_system_id' => $account->api_system_id,
        'is_manually_enabled' => true,
        'overlaps_with_binance' => true,
        'is_marked_for_delisting' => false,
        'has_no_indicator_data' => false,
        'has_price_trend_misalignment' => false,
        'has_early_direction_change' => false,
        'has_invalid_indicator_direction' => false,
        'api_statuses' => [
            'cmc_api_called' => true,
            'taapi_verified' => true,
            'has_taapi_data' => true,
        ],
        'direction' => 'LONG',
        'indicators_timeframe' => '1h',
        'min_notional' => 10.0,
        'tick_size' => 0.0001,
        'price_precision' => 4,
        'quantity_precision' => 2,
        'leverage_brackets' => [['bracket' => 1, 'initialLeverage' => 50, 'notionalCap' => 100000, 'maintMarginRatio' => 0.01]],
        'btc_correlation_rolling' => $correlation,
        'btc_correlation_pearson' => $correlation,
        'btc_correlation_spearman' => $correlation,
        'btc_elasticity_long' => $elasticityLong,
        'btc_elasticity_short' => $elasticityShort,
    ]);
}

function makeBtcForStaleGateTest(Account $account): ExchangeSymbol
{
    $btcSymbol = Symbol::firstOrCreate(['token' => 'BTC'], ['name' => 'Bitcoin']);

    return ExchangeSymbol::updateOrCreate(
        ['token' => 'BTC', 'quote' => $account->trading_quote, 'api_system_id' => $account->api_system_id],
        [
            'symbol_id' => $btcSymbol->id,
            'is_manually_enabled' => true,
            'overlaps_with_binance' => true,
            'is_marked_for_delisting' => false,
            'has_no_indicator_data' => false,
            'has_price_trend_misalignment' => false,
            'has_early_direction_change' => false,
            'has_invalid_indicator_direction' => false,
            'api_statuses' => ['cmc_api_called' => true, 'taapi_verified' => true, 'has_taapi_data' => true],
            'direction' => 'LONG',
            'indicators_timeframe' => '1h',
            'min_notional' => 10.0,
            'tick_size' => 0.01,
            'price_precision' => 2,
            'quantity_precision' => 3,
            'leverage_brackets' => [['bracket' => 1, 'initialLeverage' => 125, 'notionalCap' => 1000000, 'maintMarginRatio' => 0.004]],
        ]
    );
}

function seedSidecarPrice(ExchangeSymbol $symbol, ?Carbon\CarbonInterface $syncedAt): void
{
    ExchangeSymbolPrice::updateOrCreate(
        ['exchange_symbol_id' => $symbol->id],
        [
            'mark_price' => '100.00000000',
            'mark_price_synced_at' => $syncedAt,
        ]
    );
}

function makeNewLongPositionSlot(Account $account): Position
{
    return Position::factory()->create([
        'account_id' => $account->id,
        'status' => 'new',
        'direction' => 'LONG',
        'exchange_symbol_id' => null,
    ]);
}

it('filters out tokens whose sidecar mark_price is older than the freshness threshold', function (): void {
    config()->set('kraite.token_discovery.mark_price_max_age_seconds', 30);

    $account = createAccountForStaleGateTest();
    $btc = makeBtcForStaleGateTest($account);
    seedSidecarPrice($btc, now());

    // Stale candidate — would win on score (highest elasticity × |correlation|)
    // but its sidecar `mark_price_synced_at` is 60s old, beyond the
    // 30s freshness threshold. The gate must drop it.
    $stale = makeTradeableSymbol(
        'STALETKN',
        $account,
        ['1h' => 0.9],
        ['1h' => 2.0],
        ['1h' => -0.5],
    );
    seedSidecarPrice($stale, now()->subSeconds(60));

    // Fresh candidate — lower score but sidecar is current. Wins by
    // elimination once the gate filters the stale one out.
    $fresh = makeTradeableSymbol(
        'FRESHTKN',
        $account,
        ['1h' => 0.4],
        ['1h' => 1.0],
        ['1h' => -0.3],
    );
    seedSidecarPrice($fresh, now());

    makeNewLongPositionSlot($account);

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    expect($position->exchange_symbol_id)->toBe($fresh->id,
        'Stale-priced STALETKN was beating FRESHTKN on raw score, but the '
        .'freshness gate must drop it before scoring runs. The gate is what '
        .'protects production from a daemon stall feeding bad open decisions '
        .'across all accounts simultaneously.'
    );
});

it('opens nothing when every candidate is stale (general daemon stall shape)', function (): void {
    config()->set('kraite.token_discovery.mark_price_max_age_seconds', 30);

    $account = createAccountForStaleGateTest();
    $btc = makeBtcForStaleGateTest($account);
    seedSidecarPrice($btc, now());

    $stale1 = makeTradeableSymbol('STALE1', $account, ['1h' => 0.5], ['1h' => 1.0], ['1h' => -0.3]);
    $stale2 = makeTradeableSymbol('STALE2', $account, ['1h' => 0.7], ['1h' => 1.5], ['1h' => -0.4]);
    seedSidecarPrice($stale1, now()->subMinutes(2));
    seedSidecarPrice($stale2, now()->subMinutes(5));

    makeNewLongPositionSlot($account);

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    expect($position?->exchange_symbol_id)->toBeNull(
        'During a general daemon stall every candidate has a stale sidecar. '
        .'The gate must produce zero assignments — no opens — rather than '
        .'pick the least-stale option. Refusing to act beats acting on bad data.'
    );
});

it('does not regress symbols with null sidecar (legacy / brand-new symbol path)', function (): void {
    config()->set('kraite.token_discovery.mark_price_max_age_seconds', 30);

    $account = createAccountForStaleGateTest();
    makeBtcForStaleGateTest($account);
    // No sidecar seed for BTC — null synced_at, allowed through.

    // Single candidate, no sidecar row at all.
    $candidate = makeTradeableSymbol(
        'NOSIDECAR',
        $account,
        ['1h' => 0.6],
        ['1h' => 1.2],
        ['1h' => -0.4],
    );

    makeNewLongPositionSlot($account);

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    expect($position->exchange_symbol_id)->toBe($candidate->id,
        'Null sidecar (legacy column path, brand-new symbol, test fixture) '
        .'must NOT be treated as stale by the gate — only an explicit stale '
        .'synced_at triggers the drop. Sizing-time null-mark_price throws '
        .'are the defence in depth for the null-everything case.'
    );
});
