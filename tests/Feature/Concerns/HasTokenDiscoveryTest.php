<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite as KraiteSettings;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Models\TradeConfiguration;
use StepDispatcher\Models\StepsDispatcher;

beforeEach(function (): void {
    // The timeframe list used to live per-exchange on `api_systems.timeframes`;
    // after the 2026-04-24 move it's a single kraite-singleton column. Seed
    // the canonical four-slot set so every test that doesn't call the
    // helper below (which overrides with a larger set) still has a
    // sensible default for the code under test.
    KraiteSettings::updateOrCreate(
        ['id' => 1],
        ['timeframes' => ['1h', '4h', '12h', '1d']]
    );
});

/**
 * Helper to create a test account with all required relationships
 */
function createAccountForTokenDiscoveryTest(string $suffix = ''): Account
{
    $apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        [
            'name' => 'Binance',
            'is_exchange' => true,
        ]
    );

    // Override the default beforeEach set with the 5-timeframe fixture
    // this helper's suite of tests expects (tests scoring across 5m..1d).
    KraiteSettings::updateOrCreate(
        ['id' => 1],
        ['timeframes' => ['5m', '1h', '4h', '12h', '1d']]
    );

    // Create ISOLATED test quote to avoid collision with seeded symbols
    $testQuoteCanonical = 'TESTQUOTE'.fake()->randomNumber(6);

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
        'total_positions_long' => 2,
        'total_positions_short' => 2,
    ]);
}

/**
 * Helper to create an exchange symbol with complete correlation/elasticity data
 */
function createExchangeSymbolWithData(
    #[SensitiveParameter] string $token,
    string $direction,
    array $correlationData,
    array $elasticityLongData,
    array $elasticityShortData,
    ?int $apiSystemId = null,
    ?string $quote = null
): ExchangeSymbol {
    // Note: These should always be passed from account context for isolation
    // The fallback is only for backward compatibility
    if (! $apiSystemId) {
        $apiSystem = ApiSystem::where('canonical', 'binance')->first();
        $apiSystemId = $apiSystem->id;
    }

    if (! $quote) {
        $quote = 'USDT';
    }

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
        'btc_correlation_rolling' => $correlationData,
        'btc_correlation_pearson' => $correlationData,
        'btc_correlation_spearman' => $correlationData,
        'btc_elasticity_long' => $elasticityLongData,
        'btc_elasticity_short' => $elasticityShortData,
    ]);
}

/**
 * Helper to create BTC exchange symbol (the bias reference token)
 */
function createBtcExchangeSymbol(?string $direction = null, ?string $timeframe = null, ?int $apiSystemId = null, ?string $quote = null): ExchangeSymbol
{
    // Note: apiSystemId and quote should always be passed from account context for isolation
    if (! $apiSystemId || ! $quote) {
        throw new InvalidArgumentException('apiSystemId and quote must be provided for test isolation');
    }

    $btcSymbol = Symbol::firstOrCreate(
        ['token' => 'BTC'],
        ['name' => 'Bitcoin']
    );

    // Use updateOrCreate to handle existing BTC exchange symbols
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
            'indicators_timeframe' => $timeframe,
            'min_notional' => 10.0,
            'tick_size' => 0.01,
            'price_precision' => 2,
            'quantity_precision' => 3,
            'leverage_brackets' => [['bracket' => 1, 'initialLeverage' => 125, 'notionalCap' => 1000000, 'maintMarginRatio' => 0.004]], // Required for tradeable() scope
        ]
    );
}

/**
 * Helper to create a new position slot for an account
 */
function createPositionSlot(Account $account, string $direction): Position
{
    return Position::factory()->create([
        'account_id' => $account->id,
        'uuid' => fake()->uuid(),
        'status' => 'new',
        'direction' => $direction,
        'exchange_symbol_id' => null,
    ]);
}

/**
 * Helper to create a fast-traded (recently closed, quick, profitable) position
 */
function createFastTradedPosition(Account $account, ExchangeSymbol $exchangeSymbol, string $direction): Position
{
    return Position::factory()->create([
        'account_id' => $account->id,
        'uuid' => fake()->uuid(),
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $exchangeSymbol->parsed_trading_pair,
        'status' => 'closed',
        'direction' => $direction,
        'was_fast_traded' => true,
        'opened_at' => now()->subMinutes(5),
        'closed_at' => now()->subMinutes(2), // Closed recently, quick trade
    ]);
}

beforeEach(function (): void {
    StepsDispatcher::updateOrCreate(['group' => 'alpha'], ['can_dispatch' => true]);
    StepsDispatcher::updateOrCreate(['group' => 'beta'], ['can_dispatch' => true]);

    // Default config values
    Config::set('kraite.token_discovery.correlation_type', 'pearson');
    Config::set('kraite.token_discovery.btc_biased_restriction', true);
    Config::set('kraite.token_discovery.require_matching_correlation_sign', true);
    Config::set('kraite.correlation.btc_token', 'BTC');
});

/*
|--------------------------------------------------------------------------
| BTC Bias Algorithm - Core Tests
|--------------------------------------------------------------------------
|
| Tests the main BTC bias-based token selection algorithm:
| - BTC has direction -> use BTC's timeframe
| - Score = elasticity × |correlation|
| - Correlation sign filtering based on direction alignment
|
*/

test('selects token with highest score when BTC has LONG direction and position is LONG', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // Create BTC with LONG direction and 1h timeframe
    $btc = createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Create tokens with different scores (elasticity × |correlation|)
    // BTC=LONG + Position=LONG -> want POSITIVE correlation

    // Token A: Low score (elasticity=0.5, correlation=0.3) = 0.15
    createExchangeSymbolWithData(
        'TOKENA',
        'LONG',
        ['1h' => 0.3],  // Positive correlation (good for LONG+LONG)
        ['1h' => 0.5],  // elasticity_long
        ['1h' => -0.4],
        $account->api_system_id,
        $account->trading_quote
    );

    // Token B: High score (elasticity=1.5, correlation=0.8) = 1.2
    $tokenB = createExchangeSymbolWithData(
        'TOKENB',
        'LONG',
        ['1h' => 0.8],  // Higher positive correlation
        ['1h' => 1.5],  // Higher elasticity_long
        ['1h' => -0.6],
        $account->api_system_id,
        $account->trading_quote
    );

    // Token C: Medium score (elasticity=1.0, correlation=0.5) = 0.5
    createExchangeSymbolWithData(
        'TOKENC',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // Create a LONG position slot
    createPositionSlot($account, 'LONG');

    $result = $account->assignBestTokenToNewPositions();

    expect($result)->toContain('TOKENB'); // Highest score should be selected

    // Verify position was assigned
    $position = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    expect($position)->not->toBeNull();
    expect($position->exchange_symbol_id)->toBe($tokenB->id);
});

test('selects token with NEGATIVE correlation when BTC is LONG and position is SHORT', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // BTC=LONG + Position=SHORT -> want NEGATIVE correlation

    // Token A: Positive correlation (wrong sign for SHORT position)
    createExchangeSymbolWithData(
        'WRONGSIGN',
        'SHORT',
        ['1h' => 0.8],  // POSITIVE - wrong for BTC=LONG + Position=SHORT
        ['1h' => 1.0],
        ['1h' => -1.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // Token B: Negative correlation (correct sign for SHORT position)
    $correctToken = createExchangeSymbolWithData(
        'RIGHTSIGN',
        'SHORT',
        ['1h' => -0.6],  // NEGATIVE - correct for BTC=LONG + Position=SHORT
        ['1h' => 0.8],
        ['1h' => -1.2],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'SHORT');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->whereNotNull('exchange_symbol_id')
        ->first();

    // Should select RIGHTSIGN (negative correlation for opposite direction)
    expect($position->exchange_symbol_id)->toBe($correctToken->id);
});

test('selects token with NEGATIVE correlation when BTC is SHORT and position is LONG', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is SHORT
    createBtcExchangeSymbol('SHORT', '1h', $account->api_system_id, $account->trading_quote);

    // BTC=SHORT + Position=LONG -> want NEGATIVE correlation

    // Token A: Positive correlation (wrong sign)
    createExchangeSymbolWithData(
        'WRONGSIGNB',
        'LONG',
        ['1h' => 0.7],  // POSITIVE - wrong for BTC=SHORT + Position=LONG
        ['1h' => 1.0],
        ['1h' => -0.8],
        $account->api_system_id,
        $account->trading_quote
    );

    // Token B: Negative correlation (correct sign)
    $correctToken = createExchangeSymbolWithData(
        'RIGHTSIGNB',
        'LONG',
        ['1h' => -0.5],  // NEGATIVE - correct for BTC=SHORT + Position=LONG
        ['1h' => 1.2],
        ['1h' => -0.9],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->whereNotNull('exchange_symbol_id')
        ->first();

    expect($position->exchange_symbol_id)->toBe($correctToken->id);
});

test('selects token with POSITIVE correlation when BTC is SHORT and position is SHORT', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is SHORT
    createBtcExchangeSymbol('SHORT', '1h', $account->api_system_id, $account->trading_quote);

    // BTC=SHORT + Position=SHORT -> want POSITIVE correlation (same direction)

    // Token A: Negative correlation (wrong sign)
    createExchangeSymbolWithData(
        'NEGSIGNSS',
        'SHORT',
        ['1h' => -0.7],  // NEGATIVE - wrong for same direction
        ['1h' => 0.8],
        ['1h' => -1.0],
        $account->api_system_id,
        $account->trading_quote
    );

    // Token B: Positive correlation (correct sign for same direction)
    $correctToken = createExchangeSymbolWithData(
        'POSSIGNSS',
        'SHORT',
        ['1h' => 0.6],  // POSITIVE - correct for BTC=SHORT + Position=SHORT
        ['1h' => 0.9],
        ['1h' => -1.2],  // elasticity_short (absolute value used)
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'SHORT');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->whereNotNull('exchange_symbol_id')
        ->first();

    expect($position->exchange_symbol_id)->toBe($correctToken->id);
});

test('uses symbol own timeframe for scoring not BTC timeframe', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC has 4h timeframe (not used for scoring anymore)
    createBtcExchangeSymbol('LONG', '4h', $account->api_system_id, $account->trading_quote);

    // Token with indicators_timeframe = 1h has great 1h scores
    // Helper sets indicators_timeframe to '1h' by default
    $token1h = createExchangeSymbolWithData(
        'GOOD1HBAD4H',
        'LONG',
        ['1h' => 0.9, '4h' => 0.2],  // Great 1h, poor 4h
        ['1h' => 2.0, '4h' => 0.3],
        ['1h' => -1.0, '4h' => -0.2],
        $account->api_system_id,
        $account->trading_quote
    );

    // Token with indicators_timeframe = 1h has poor 1h scores
    createExchangeSymbolWithData(
        'BAD1HGOOD4H',
        'LONG',
        ['1h' => 0.2, '4h' => 0.8],  // Poor 1h, great 4h
        ['1h' => 0.3, '4h' => 1.5],
        ['1h' => -0.2, '4h' => -1.0],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->whereNotNull('exchange_symbol_id')
        ->first();

    // Should select GOOD1HBAD4H because symbol's indicators_timeframe is 1h, not BTC's 4h
    expect($position->exchange_symbol_id)->toBe($token1h->id);
});

/*
|--------------------------------------------------------------------------
| BTC Biased Restriction Tests
|--------------------------------------------------------------------------
|
| When BTC has NO direction:
| - btc_biased_restriction=true: Delete all position slots (STRICT mode)
| - btc_biased_restriction=false: Fallback to non-BTC algorithm (RELAXED mode)
|
*/

test('deletes all position slots when BTC has no direction and btc_biased_restriction is true', function (): void {
    Config::set('kraite.token_discovery.btc_biased_restriction', true);

    $account = createAccountForTokenDiscoveryTest();

    // Create BTC with NO direction
    createBtcExchangeSymbol(null, null, $account->api_system_id, $account->trading_quote);

    // Create available tokens
    createExchangeSymbolWithData(
        'AVAILTOKEN',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // Create position slots
    createPositionSlot($account, 'LONG');
    createPositionSlot($account, 'SHORT');

    $initialCount = Position::where('account_id', $account->id)->count();
    expect($initialCount)->toBe(2);

    $result = $account->assignBestTokenToNewPositions();

    expect($result)->toBe(''); // Empty string returned

    // All slots should be deleted
    $finalCount = Position::where('account_id', $account->id)->count();
    expect($finalCount)->toBe(0);
});

test('uses fallback algorithm when BTC has no direction and btc_biased_restriction is false', function (): void {
    Config::set('kraite.token_discovery.btc_biased_restriction', false);

    $account = createAccountForTokenDiscoveryTest();

    // Create BTC with NO direction
    createBtcExchangeSymbol(null, null, $account->api_system_id, $account->trading_quote);

    // Create available token
    $token = createExchangeSymbolWithData(
        'FALLBACKTOKEN',
        'LONG',
        ['1h' => 0.5, '4h' => 0.7],
        ['1h' => 1.0, '4h' => 1.2],
        ['1h' => -0.5, '4h' => -0.6],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');

    $result = $account->assignBestTokenToNewPositions();

    expect($result)->toContain('FALLBACKTOKEN');

    $position = Position::where('account_id', $account->id)
        ->whereNotNull('exchange_symbol_id')
        ->first();

    expect($position)->not->toBeNull();
    expect($position->exchange_symbol_id)->toBe($token->id);
});

/*
|--------------------------------------------------------------------------
| Correlation Sign Filtering Tests
|--------------------------------------------------------------------------
|
| require_matching_correlation_sign config:
| - true: Only select tokens with correct correlation sign
| - false: Select best score regardless of sign
|
*/

test('deletes position slot when no tokens match correlation sign requirement', function (): void {
    Config::set('kraite.token_discovery.require_matching_correlation_sign', true);

    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Only create tokens with NEGATIVE correlation
    // BTC=LONG + Position=LONG wants POSITIVE correlation
    createExchangeSymbolWithData(
        'NEGONLY1',
        'LONG',
        ['1h' => -0.8],  // NEGATIVE - wrong for LONG+LONG
        ['1h' => 1.5],
        ['1h' => -1.0],
        $account->api_system_id,
        $account->trading_quote
    );

    createExchangeSymbolWithData(
        'NEGONLY2',
        'LONG',
        ['1h' => -0.5],  // NEGATIVE - wrong for LONG+LONG
        ['1h' => 1.2],
        ['1h' => -0.8],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    // Position should be deleted (no matching tokens)
    $remainingPositions = Position::where('account_id', $account->id)->count();
    expect($remainingPositions)->toBe(0);
});

test('selects best token regardless of correlation sign when require_matching_correlation_sign is false', function (): void {
    Config::set('kraite.token_discovery.require_matching_correlation_sign', false);

    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Create tokens with wrong correlation sign but high scores
    // BTC=LONG + Position=LONG normally wants POSITIVE, but we disabled sign check

    // Low score positive correlation
    createExchangeSymbolWithData(
        'LOWPOSITIVE',
        'LONG',
        ['1h' => 0.2],  // Positive (would be "correct" sign)
        ['1h' => 0.5],
        ['1h' => -0.3],
        $account->api_system_id,
        $account->trading_quote
    );

    // High score negative correlation (would be "wrong" sign)
    $highScoreWrongSign = createExchangeSymbolWithData(
        'HIGHNEGATIVE',
        'LONG',
        ['1h' => -0.9],  // NEGATIVE - normally "wrong" for LONG+LONG
        ['1h' => 2.0],   // But high elasticity gives best score
        ['1h' => -1.5],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->whereNotNull('exchange_symbol_id')
        ->first();

    // Should select HIGHNEGATIVE despite wrong sign (sign check disabled)
    expect($position->exchange_symbol_id)->toBe($highScoreWrongSign->id);
});

/*
|--------------------------------------------------------------------------
| Fallback Algorithm Tests
|--------------------------------------------------------------------------
|
| When BTC has no direction and btc_biased_restriction=false:
| - Iterate ALL timeframes
| - No correlation sign filtering
| - Select best score across all timeframes
|
*/

test('fallback algorithm scores across all timeframes', function (): void {
    Config::set('kraite.token_discovery.btc_biased_restriction', false);

    $account = createAccountForTokenDiscoveryTest();

    // No BTC direction
    createBtcExchangeSymbol(null, null, $account->api_system_id, $account->trading_quote);

    // Token A: Best score on 1h (0.8 × 1.0 = 0.8)
    createExchangeSymbolWithData(
        'BEST1H',
        'LONG',
        ['1h' => 0.8, '4h' => 0.2],
        ['1h' => 1.0, '4h' => 0.3],
        ['1h' => -0.5, '4h' => -0.2],
        $account->api_system_id,
        $account->trading_quote
    );

    // Token B: Best score on 4h (0.9 × 1.5 = 1.35) - overall winner
    $bestOverall = createExchangeSymbolWithData(
        'BEST4H',
        'LONG',
        ['1h' => 0.3, '4h' => 0.9],
        ['1h' => 0.4, '4h' => 1.5],
        ['1h' => -0.3, '4h' => -1.0],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->whereNotNull('exchange_symbol_id')
        ->first();

    // Should select BEST4H because its 4h score is highest overall
    expect($position->exchange_symbol_id)->toBe($bestOverall->id);
});

test('fallback algorithm ignores correlation sign', function (): void {
    Config::set('kraite.token_discovery.btc_biased_restriction', false);
    Config::set('kraite.token_discovery.require_matching_correlation_sign', true); // Even with this true

    $account = createAccountForTokenDiscoveryTest();

    // No BTC direction
    createBtcExchangeSymbol(null, null, $account->api_system_id, $account->trading_quote);

    // Create token with negative correlation (would be filtered in BTC bias mode)
    $negativeCorrelation = createExchangeSymbolWithData(
        'NEGATIVECORR',
        'LONG',
        ['1h' => -0.9],  // Negative correlation
        ['1h' => 1.5],
        ['1h' => -1.0],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->whereNotNull('exchange_symbol_id')
        ->first();

    // Should still select it because fallback ignores sign
    expect($position->exchange_symbol_id)->toBe($negativeCorrelation->id);
});

/*
|--------------------------------------------------------------------------
| Fast-Tracked Symbol Tests
|--------------------------------------------------------------------------
|
| Fast-tracked symbols (recently profitable quick trades):
| - Priority 1 - checked before scoring
| - Only verify direction match
| - Skip correlation/elasticity checks
|
*/

test('prioritizes fast-tracked symbols over scored tokens', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Create a token with excellent score
    createExchangeSymbolWithData(
        'BESTSCORE',
        'LONG',
        ['1h' => 0.95],
        ['1h' => 2.0],
        ['1h' => -1.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // Create a token with mediocre score but was fast-traded recently
    $fastTrackedToken = createExchangeSymbolWithData(
        'FASTTRACK',
        'LONG',
        ['1h' => 0.3],  // Lower correlation
        ['1h' => 0.5],  // Lower elasticity
        ['1h' => -0.4],
        $account->api_system_id,
        $account->trading_quote
    );

    // Create fast-traded position history for the mediocre token
    createFastTradedPosition($account, $fastTrackedToken, 'LONG');

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    // Should select FASTTRACK despite lower score (fast-track priority)
    expect($position->exchange_symbol_id)->toBe($fastTrackedToken->id);
});

test('fast-tracked symbol must have matching direction', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Fast-tracked token changed direction from LONG to SHORT
    $changedDirectionToken = createExchangeSymbolWithData(
        'CHANGEDDIR',
        'SHORT',  // Now SHORT
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.8],
        $account->api_system_id,
        $account->trading_quote
    );

    // Historical fast trade was LONG
    createFastTradedPosition($account, $changedDirectionToken, 'LONG');

    // Available token with correct LONG direction
    $correctDirectionToken = createExchangeSymbolWithData(
        'CORRECTDIR',
        'LONG',
        ['1h' => 0.6],
        ['1h' => 1.1],
        ['1h' => -0.7],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    // Should NOT use CHANGEDDIR (direction changed), should use CORRECTDIR
    expect($position->exchange_symbol_id)->toBe($correctDirectionToken->id);
});

test('fast-tracked symbol skips correlation sign check', function (): void {
    Config::set('kraite.token_discovery.require_matching_correlation_sign', true);

    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Fast-tracked token with WRONG correlation sign for BTC=LONG + Position=LONG
    $wrongSignFastTrack = createExchangeSymbolWithData(
        'WRONGSIGNFT',
        'LONG',
        ['1h' => -0.8],  // NEGATIVE - would be filtered in normal BTC bias
        ['1h' => 0.5],
        ['1h' => -0.4],
        $account->api_system_id,
        $account->trading_quote
    );

    // Create fast-traded history
    createFastTradedPosition($account, $wrongSignFastTrack, 'LONG');

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    // Should still select WRONGSIGNFT because fast-track skips correlation check
    expect($position->exchange_symbol_id)->toBe($wrongSignFastTrack->id);
});

/*
|--------------------------------------------------------------------------
| Multiple Position Slots Tests
|--------------------------------------------------------------------------
*/

test('assigns different tokens to multiple position slots', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Create multiple tokens
    $token1 = createExchangeSymbolWithData(
        'MULTI1',
        'LONG',
        ['1h' => 0.9],
        ['1h' => 1.5],
        ['1h' => -1.0],
        $account->api_system_id,
        $account->trading_quote
    );

    $token2 = createExchangeSymbolWithData(
        'MULTI2',
        'LONG',
        ['1h' => 0.7],
        ['1h' => 1.2],
        ['1h' => -0.8],
        $account->api_system_id,
        $account->trading_quote
    );

    // Create two position slots
    createPositionSlot($account, 'LONG');
    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $positions = Position::where('account_id', $account->id)
        ->whereNotNull('exchange_symbol_id')
        ->get();

    expect($positions)->toHaveCount(2);

    $assignedTokenIds = $positions->pluck('exchange_symbol_id')->toArray();
    expect($assignedTokenIds)->toContain($token1->id);
    expect($assignedTokenIds)->toContain($token2->id);

    // Each token should only be assigned once (batch exclusion)
    expect(array_unique($assignedTokenIds))->toHaveCount(2);
});

test('handles mixed LONG and SHORT position slots correctly', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // LONG token (positive correlation for BTC=LONG + Position=LONG)
    $longToken = createExchangeSymbolWithData(
        'LONGTOKEN',
        'LONG',
        ['1h' => 0.8],  // Positive
        ['1h' => 1.5],
        ['1h' => -1.0],
        $account->api_system_id,
        $account->trading_quote
    );

    // SHORT token (negative correlation for BTC=LONG + Position=SHORT)
    $shortToken = createExchangeSymbolWithData(
        'SHORTTOKEN',
        'SHORT',
        ['1h' => -0.7],  // Negative
        ['1h' => 0.8],
        ['1h' => -1.2],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');
    createPositionSlot($account, 'SHORT');

    $account->assignBestTokenToNewPositions();

    $longPosition = Position::where('account_id', $account->id)
        ->where('direction', 'LONG')
        ->whereNotNull('exchange_symbol_id')
        ->first();

    $shortPosition = Position::where('account_id', $account->id)
        ->where('direction', 'SHORT')
        ->whereNotNull('exchange_symbol_id')
        ->first();

    expect($longPosition->exchange_symbol_id)->toBe($longToken->id);
    expect($shortPosition->exchange_symbol_id)->toBe($shortToken->id);
});

/*
|--------------------------------------------------------------------------
| Edge Cases
|--------------------------------------------------------------------------
*/

test('handles no available exchange symbols gracefully', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG but no other tradeable symbols
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    // Position should be deleted (no tokens available)
    $remainingPositions = Position::where('account_id', $account->id)->count();
    expect($remainingPositions)->toBe(0);
});

test('automatic system blocks exclude a symbol without mutating the sysadmin flag', function (): void {
    $account = createAccountForTokenDiscoveryTest();
    $exchangeSymbol = createExchangeSymbolWithData(
        'SYSTEMBLOCKED',
        'LONG',
        ['1h' => 0.8],
        ['1h' => 1.2],
        ['1h' => -1.2],
        $account->api_system_id,
        $account->trading_quote,
    );

    expect(ExchangeSymbol::tradeable()->whereKey($exchangeSymbol->id)->exists())->toBeTrue();

    $exchangeSymbol->update([
        'system_disabled_at' => now(),
        'system_disabled_reason' => 'position_opening_failed',
    ]);

    expect($exchangeSymbol->fresh()->is_manually_enabled)->toBeTrue()
        ->and($exchangeSymbol->fresh()->isTradeable())->toBeFalse()
        ->and(ExchangeSymbol::tradeable()->whereKey($exchangeSymbol->id)->exists())->toBeFalse();
});

test('price alignment gates agree between the model and tradeable scope', function (): void {
    $account = createAccountForTokenDiscoveryTest();
    $exchangeSymbol = createExchangeSymbolWithData(
        'MISALIGNED',
        'LONG',
        ['1h' => 0.8],
        ['1h' => 1.2],
        ['1h' => -1.2],
        $account->api_system_id,
        $account->trading_quote,
    );

    expect($exchangeSymbol->isTradeable())->toBeTrue()
        ->and(ExchangeSymbol::tradeable()->whereKey($exchangeSymbol->id)->exists())->toBeTrue();

    $exchangeSymbol->update(['is_price_aligned' => false]);

    expect($exchangeSymbol->fresh()->isTradeable())->toBeFalse()
        ->and(ExchangeSymbol::tradeable()->whereKey($exchangeSymbol->id)->exists())->toBeFalse();
});

test('handles symbols with incomplete data (missing own timeframe correlation)', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG with 4h timeframe
    createBtcExchangeSymbol('LONG', '4h', $account->api_system_id, $account->trading_quote);

    // Token with indicators_timeframe = 1h but missing 1h correlation data
    // This symbol won't be tradeable because it doesn't have correlation for its own timeframe
    $symbolMissing = Symbol::factory()->create(['token' => 'MISSINGOWN']);
    ExchangeSymbol::factory()->create([
        'token' => 'MISSINGOWN',
        'quote' => $account->trading_quote,
        'symbol_id' => $symbolMissing->id,
        'api_system_id' => $account->api_system_id,
        'is_manually_enabled' => true,
        'overlaps_with_binance' => true,
        'is_marked_for_delisting' => false,
        'api_statuses' => [
            'cmc_api_called' => true,
            'taapi_verified' => true,
            'has_taapi_data' => true,
        ],
        'direction' => 'LONG',
        'indicators_timeframe' => '1h',  // Symbol's timeframe is 1h
        'min_notional' => 10.0,
        'tick_size' => 0.0001,
        'price_precision' => 4,
        'quantity_precision' => 2,
        'btc_correlation_rolling' => ['4h' => 0.9],    // Has 4h but NOT 1h (its own timeframe)
        'btc_correlation_pearson' => ['4h' => 0.9],
        'btc_correlation_spearman' => ['4h' => 0.9],
        'btc_elasticity_long' => ['1h' => 1.5, '4h' => 1.5],
        'btc_elasticity_short' => ['1h' => -1.0, '4h' => -1.0],
    ]);

    // Token with complete data for its own timeframe (1h)
    $completeToken = createExchangeSymbolWithData(
        'COMPLETEOWN',
        'LONG',
        ['1h' => 0.5, '4h' => 0.6],  // Has 1h correlation (its own timeframe)
        ['1h' => 1.0, '4h' => 1.1],
        ['1h' => -0.5, '4h' => -0.6],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->whereNotNull('exchange_symbol_id')
        ->first();

    // Should select COMPLETEOWN (MISSINGOWN doesn't have correlation for its own timeframe 1h)
    expect($position->exchange_symbol_id)->toBe($completeToken->id);
});

test('excludes symbols already in opened positions', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Best scoring token but already in opened position
    $alreadyOpened = createExchangeSymbolWithData(
        'ALREADYOPEN',
        'LONG',
        ['1h' => 0.95],
        ['1h' => 2.0],
        ['1h' => -1.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // Create active position for this token (ongoing = symbol in use)
    Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $alreadyOpened->id,
        'status' => 'active',
        'direction' => 'LONG',
    ]);

    // Available token (not as good but available)
    $availableToken = createExchangeSymbolWithData(
        'AVAILABLE',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $newPosition = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    // Should select AVAILABLE (ALREADYOPEN is excluded)
    expect($newPosition->exchange_symbol_id)->toBe($availableToken->id);
});

test('an opened LONG symbol cannot be assigned to a new SHORT slot', function (): void {
    $account = createAccountForTokenDiscoveryTest();
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    $openedSymbol = createExchangeSymbolWithData(
        'CROSSDIRECTIONOPEN',
        'LONG',
        ['1h' => -0.95],
        ['1h' => 2.0],
        ['1h' => -2.0],
        $account->api_system_id,
        $account->trading_quote,
    );

    Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $openedSymbol->id,
        'parsed_trading_pair' => $openedSymbol->parsed_trading_pair,
        'status' => 'active',
        'direction' => 'LONG',
    ]);

    $openedSymbol->update(['direction' => 'SHORT']);

    $availableShort = createExchangeSymbolWithData(
        'AVAILABLESHORT',
        'SHORT',
        ['1h' => -0.5],
        ['1h' => 1.0],
        ['1h' => -1.0],
        $account->api_system_id,
        $account->trading_quote,
    );

    createPositionSlot($account, 'SHORT');
    $account->assignBestTokenToNewPositions();

    $newShort = Position::query()
        ->where('account_id', $account->id)
        ->where('status', 'new')
        ->where('direction', 'SHORT')
        ->sole();

    expect($newShort->exchange_symbol_id)->toBe($availableShort->id)
        ->and($newShort->exchange_symbol_id)->not->toBe($openedSymbol->id);
});

test('filters symbols missing required trading metadata', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Token with missing min_notional - use account's api_system instead of hardcoded lookup
    $symbolMissing = Symbol::factory()->create(['token' => 'MISSINGDATA']);

    ExchangeSymbol::factory()->create([
        'token' => 'MISSINGDATA',
        'quote' => $account->trading_quote,
        'symbol_id' => $symbolMissing->id,
        'api_system_id' => $account->api_system_id,
        'is_manually_enabled' => true,
        'direction' => 'LONG',
        'min_notional' => null,  // MISSING!
        'tick_size' => 0.0001,
        'btc_correlation_pearson' => ['1h' => 0.9],
        'btc_elasticity_long' => ['1h' => 2.0],
        'btc_elasticity_short' => ['1h' => -1.5],
    ]);

    // Complete token
    $completeToken = createExchangeSymbolWithData(
        'COMPLETEMD',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->whereNotNull('exchange_symbol_id')
        ->first();

    // Should select COMPLETEMD (MISSINGDATA filtered out)
    expect($position->exchange_symbol_id)->toBe($completeToken->id);
});

test('uses correct correlation type from config', function (): void {
    Config::set('kraite.token_discovery.correlation_type', 'spearman');

    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Create token with different correlation values per type - use account's api_system
    $symbol = Symbol::factory()->create(['token' => 'CORRTYPE']);

    $token = ExchangeSymbol::factory()->create([
        'token' => 'CORRTYPE',
        'quote' => $account->trading_quote,
        'symbol_id' => $symbol->id,
        'api_system_id' => $account->api_system_id,
        'is_manually_enabled' => true,
        'overlaps_with_binance' => true,  // Required for tradeable() scope
        'is_marked_for_delisting' => false,  // Required for tradeable() scope
        'has_no_indicator_data' => false, // Required for tradeable() scope
        'has_price_trend_misalignment' => false, // Required for tradeable() scope
        'has_early_direction_change' => false, // Required for tradeable() scope
        'has_invalid_indicator_direction' => false, // Required for tradeable() scope
        'api_statuses' => [
            'cmc_api_called' => true,
            'taapi_verified' => true,
            'has_taapi_data' => true, // Required for tradeable() scope
        ],
        'direction' => 'LONG',
        'indicators_timeframe' => '1h',  // Required for tradeable() scope and correlation lookup
        'min_notional' => 10.0,
        'tick_size' => 0.0001,
        'price_precision' => 4,
        'quantity_precision' => 2,
        'leverage_brackets' => [['bracket' => 1, 'initialLeverage' => 50, 'notionalCap' => 100000, 'maintMarginRatio' => 0.01]], // Required for tradeable() scope
        'btc_correlation_rolling' => ['1h' => 0.2],   // Would give lower score
        'btc_correlation_pearson' => ['1h' => 0.3],   // Would give medium score
        'btc_correlation_spearman' => ['1h' => 0.9],  // Configured - highest score
        'btc_elasticity_long' => ['1h' => 1.0],
        'btc_elasticity_short' => ['1h' => -0.5],
    ]);

    createPositionSlot($account, 'LONG');

    $result = $account->assignBestTokenToNewPositions();

    // Token should be selected (spearman correlation is positive)
    expect($result)->toContain('CORRTYPE');
});

test('handles BTC exchange symbol not found for account api_system', function (): void {
    Config::set('kraite.token_discovery.btc_biased_restriction', false);

    $account = createAccountForTokenDiscoveryTest();

    // Create BTC for DIFFERENT api_system
    $otherApiSystem = ApiSystem::factory()->create(['canonical' => 'bybit']);
    createBtcExchangeSymbol('LONG', '1h', $otherApiSystem->id, $account->trading_quote);

    // No BTC for account's api_system - should use fallback
    $token = createExchangeSymbolWithData(
        'NOBTC',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->whereNotNull('exchange_symbol_id')
        ->first();

    // Should select via fallback (no BTC found for this api_system)
    expect($position->exchange_symbol_id)->toBe($token->id);
});

test('deleteUnassignedPositionSlots only deletes new positions with null exchange_symbol_id', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Create an opened position (should NOT be deleted)
    $openedPosition = Position::factory()->create([
        'account_id' => $account->id,
        'status' => 'opened',
        'direction' => 'LONG',
        'exchange_symbol_id' => null,
    ]);

    // Create a new position with assigned token (should NOT be deleted)
    $token = createExchangeSymbolWithData(
        'ASSIGNED',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    $assignedPosition = Position::factory()->create([
        'account_id' => $account->id,
        'status' => 'new',
        'direction' => 'LONG',
        'exchange_symbol_id' => $token->id,
    ]);

    // Create unassigned new position (SHOULD be deleted)
    $unassignedPosition = Position::factory()->create([
        'account_id' => $account->id,
        'status' => 'new',
        'direction' => 'LONG',
        'exchange_symbol_id' => null,
    ]);

    $deletedCount = $account->deleteUnassignedPositionSlots();

    expect($deletedCount)->toBe(1);
    expect(Position::find($openedPosition->id))->not->toBeNull();
    expect(Position::find($assignedPosition->id))->not->toBeNull();
    expect(Position::find($unassignedPosition->id))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Scoring Formula Tests
|--------------------------------------------------------------------------
*/

test('uses elasticity_long for LONG positions', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Token with high elasticity_long but low elasticity_short
    $highLongElasticity = createExchangeSymbolWithData(
        'HIGHLONGELAST',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 2.0],   // High elasticity_long
        ['1h' => -0.2],  // Low elasticity_short
        $account->api_system_id,
        $account->trading_quote
    );

    // Token with low elasticity_long but high elasticity_short
    createExchangeSymbolWithData(
        'LOWLONGELAST',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 0.3],   // Low elasticity_long
        ['1h' => -2.0],  // High elasticity_short
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->whereNotNull('exchange_symbol_id')
        ->first();

    // For LONG position, should use elasticity_long in scoring
    expect($position->exchange_symbol_id)->toBe($highLongElasticity->id);
});

test('uses absolute elasticity_short for SHORT positions', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is SHORT
    createBtcExchangeSymbol('SHORT', '1h', $account->api_system_id, $account->trading_quote);

    // Token with high |elasticity_short| (negative number with large absolute value)
    $highShortElasticity = createExchangeSymbolWithData(
        'HIGHSHORTELAST',
        'SHORT',
        ['1h' => 0.5],  // Positive correlation for SHORT+SHORT
        ['1h' => 0.2],
        ['1h' => -2.0],  // High |elasticity_short|
        $account->api_system_id,
        $account->trading_quote
    );

    // Token with low |elasticity_short|
    createExchangeSymbolWithData(
        'LOWSHORTELAST',
        'SHORT',
        ['1h' => 0.5],
        ['1h' => 2.0],
        ['1h' => -0.3],  // Low |elasticity_short|
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'SHORT');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->whereNotNull('exchange_symbol_id')
        ->first();

    // For SHORT position, should use |elasticity_short| in scoring
    expect($position->exchange_symbol_id)->toBe($highShortElasticity->id);
});

/*
|--------------------------------------------------------------------------
| Return Value Tests
|--------------------------------------------------------------------------
*/

test('returns assigned tokens string', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    createExchangeSymbolWithData(
        'RETURNTEST1',
        'LONG',
        ['1h' => 0.8],
        ['1h' => 1.5],
        ['1h' => -1.0],
        $account->api_system_id,
        $account->trading_quote
    );

    createExchangeSymbolWithData(
        'RETURNTEST2',
        'SHORT',
        ['1h' => -0.7],
        ['1h' => 0.8],
        ['1h' => -1.2],
        $account->api_system_id,
        $account->trading_quote
    );

    createPositionSlot($account, 'LONG');
    createPositionSlot($account, 'SHORT');

    $result = $account->assignBestTokenToNewPositions();

    expect($result)->toContain('RETURNTEST1');
    expect($result)->toContain('RETURNTEST2');
    expect($result)->toContain('LONG');
    expect($result)->toContain('SHORT');
});

test('returns empty string when no positions to assign', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    createExchangeSymbolWithData(
        'UNUSED',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // No position slots created

    $result = $account->assignBestTokenToNewPositions();

    expect($result)->toBe('');
});

/*
|--------------------------------------------------------------------------
| Exchange Snapshot Exclusion Tests
|--------------------------------------------------------------------------
|
| Tests that tokens already open on the exchange (from api_snapshots) are
| excluded from selection, even if not tracked in local database.
|
*/

test('excludes tokens with open positions on exchange from api_snapshots', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Best scoring token - but already open on exchange
    $openOnExchange = createExchangeSymbolWithData(
        'OPENEXCHANGE',
        'LONG',
        ['1h' => 0.95],
        ['1h' => 2.0],
        ['1h' => -1.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // Available token (not as good but not open on exchange)
    $availableToken = createExchangeSymbolWithData(
        'NOTOPEN',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // Store open position in api_snapshots (simulating exchange query result)
    // Key format: TOKENQUOTE:DIRECTION (e.g., 'OPENEXCHANGEUSDT:LONG')
    $openPositionKey = $openOnExchange->parsed_trading_pair.':LONG';
    Kraite\Core\Models\ApiSnapshot::storeFor($account, 'account-positions', [
        $openPositionKey => [
            'symbol' => $openOnExchange->parsed_trading_pair,
            'positionSide' => 'LONG',
            'positionAmt' => '0.5',
        ],
    ]);

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    // Should select NOTOPEN (OPENEXCHANGE is excluded due to api_snapshots)
    expect($position->exchange_symbol_id)->toBe($availableToken->id);
});

test('excludes tokens with open orders on exchange from api_snapshots', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Best scoring token - but has open orders on exchange
    $hasOpenOrders = createExchangeSymbolWithData(
        'HASOPENORDERS',
        'LONG',
        ['1h' => 0.95],
        ['1h' => 2.0],
        ['1h' => -1.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // Available token (not as good but no open orders)
    $noOpenOrders = createExchangeSymbolWithData(
        'NOOPENORDERS',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // Store open position in api_snapshots for the token with open orders
    // This simulates VerifyTradingPairNotOpenJob finding open positions
    $openPositionKey = $hasOpenOrders->parsed_trading_pair.':LONG';
    Kraite\Core\Models\ApiSnapshot::storeFor($account, 'account-positions', [
        $openPositionKey => [
            'symbol' => $hasOpenOrders->parsed_trading_pair,
            'positionSide' => 'LONG',
            'positionAmt' => '0.1',
        ],
    ]);

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    // Should select NOOPENORDERS (HASOPENORDERS is excluded)
    expect($position->exchange_symbol_id)->toBe($noOpenOrders->id);
});

test('allows token selection when api_snapshots has no open positions', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Best scoring token
    $bestToken = createExchangeSymbolWithData(
        'BESTTOKEN',
        'LONG',
        ['1h' => 0.95],
        ['1h' => 2.0],
        ['1h' => -1.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // Store empty open positions in api_snapshots
    Kraite\Core\Models\ApiSnapshot::storeFor($account, 'account-positions', []);

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    // Should select BESTTOKEN (no exclusions from api_snapshots)
    expect($position->exchange_symbol_id)->toBe($bestToken->id);
});

test('excludes multiple tokens open on exchange from api_snapshots', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    // BTC is LONG
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Token 1 - open on exchange
    $openToken1 = createExchangeSymbolWithData(
        'OPENTOKEN1',
        'LONG',
        ['1h' => 0.95],
        ['1h' => 2.0],
        ['1h' => -1.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // Token 2 - also open on exchange
    $openToken2 = createExchangeSymbolWithData(
        'OPENTOKEN2',
        'LONG',
        ['1h' => 0.90],
        ['1h' => 1.8],
        ['1h' => -1.3],
        $account->api_system_id,
        $account->trading_quote
    );

    // Token 3 - NOT open on exchange (only available option)
    $availableToken = createExchangeSymbolWithData(
        'AVAILABLETOKEN',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // Store multiple open positions in api_snapshots
    Kraite\Core\Models\ApiSnapshot::storeFor($account, 'account-positions', [
        $openToken1->parsed_trading_pair.':LONG' => [
            'symbol' => $openToken1->parsed_trading_pair,
            'positionSide' => 'LONG',
            'positionAmt' => '0.5',
        ],
        $openToken2->parsed_trading_pair.':SHORT' => [
            'symbol' => $openToken2->parsed_trading_pair,
            'positionSide' => 'SHORT',
            'positionAmt' => '-0.3',
        ],
    ]);

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    // Should select AVAILABLETOKEN (both OPENTOKEN1 and OPENTOKEN2 excluded)
    expect($position->exchange_symbol_id)->toBe($availableToken->id);
});

/*
|--------------------------------------------------------------------------
| Cross-Account Token Exclusion Tests
|--------------------------------------------------------------------------
|
| When user has have_distinct_position_tokens_on_all_accounts=true:
| - Tokens from ANY of the user's accounts are excluded
| - TokenMapper equivalents are also excluded (e.g., FLOKI ↔ 1000FLOKI)
|
*/

test('excludes tokens from other accounts when have_distinct_position_tokens_on_all_accounts is true', function (): void {
    // Create user with the flag enabled
    $user = Kraite\Core\Models\User::factory()->create([
        'have_distinct_position_tokens_on_all_accounts' => true,
    ]);

    // Create two accounts for the same user on different exchanges
    $binanceApiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        ['name' => 'Binance', 'is_exchange' => true]
    );

    $bybitApiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'bybit'],
        ['name' => 'Bybit', 'is_exchange' => true]
    );

    $tradeConfig = TradeConfiguration::firstOrCreate(
        ['is_default' => true],
        [
            'canonical' => 'default',
            'description' => 'Default configuration',
        ]
    );

    $testQuote = 'CROSSACCTEST'.fake()->randomNumber(5);

    $accountBinance = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $binanceApiSystem->id,
        'trade_configuration_id' => $tradeConfig->id,
        'trading_quote' => $testQuote,
        'can_trade' => true,
        'total_positions_long' => 2,
        'total_positions_short' => 2,
    ]);

    $accountBybit = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $bybitApiSystem->id,
        'trade_configuration_id' => $tradeConfig->id,
        'trading_quote' => $testQuote,
        'can_trade' => true,
        'total_positions_long' => 2,
        'total_positions_short' => 2,
    ]);

    // Create BTC for both exchanges (required for token discovery)
    createBtcExchangeSymbol('LONG', '1h', $binanceApiSystem->id, $testQuote);
    createBtcExchangeSymbol('LONG', '1h', $bybitApiSystem->id, $testQuote);

    // Create CROSSTOKEN1 on Binance first (observer sets overlaps_with_binance=true for Binance symbols)
    $tokenOnBinance = createExchangeSymbolWithData(
        'CROSSTOKEN1',
        'LONG',
        ['1h' => 0.95],
        ['1h' => 2.0],
        ['1h' => -1.5],
        $binanceApiSystem->id,
        $testQuote
    );

    // Create CROSSTOKEN2 on Binance too (required for Bybit symbol to have overlaps_with_binance=true)
    createExchangeSymbolWithData(
        'CROSSTOKEN2',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $binanceApiSystem->id,
        $testQuote
    );

    // Create active position on Binance account for CROSSTOKEN1
    Position::factory()->create([
        'account_id' => $accountBinance->id,
        'exchange_symbol_id' => $tokenOnBinance->id,
        'status' => 'active',
        'direction' => 'LONG',
    ]);

    // Create the same tokens on Bybit (observer will check Binance and set overlaps_with_binance=true)
    // CROSSTOKEN1 on Bybit (best scoring, but should be excluded due to active position on Binance)
    createExchangeSymbolWithData(
        'CROSSTOKEN1',
        'LONG',
        ['1h' => 0.95],
        ['1h' => 2.0],
        ['1h' => -1.5],
        $bybitApiSystem->id,
        $testQuote
    );

    // CROSSTOKEN2 on Bybit (available, lower score)
    $availableToken = createExchangeSymbolWithData(
        'CROSSTOKEN2',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $bybitApiSystem->id,
        $testQuote
    );

    // Create position slot on Bybit account
    createPositionSlot($accountBybit, 'LONG');

    $accountBybit->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $accountBybit->id)
        ->where('status', 'new')
        ->first();

    // Should select CROSSTOKEN2 because CROSSTOKEN1 is active on Binance account
    expect($position)->not->toBeNull();
    expect($position->exchange_symbol_id)->toBe($availableToken->id);
});

test('does not exclude tokens from other accounts when have_distinct_position_tokens_on_all_accounts is false', function (): void {
    // Create user with the flag DISABLED
    $user = Kraite\Core\Models\User::factory()->create([
        'have_distinct_position_tokens_on_all_accounts' => false,
    ]);

    $binanceApiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        ['name' => 'Binance', 'is_exchange' => true]
    );

    $bybitApiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'bybit'],
        ['name' => 'Bybit', 'is_exchange' => true]
    );

    $tradeConfig = TradeConfiguration::firstOrCreate(
        ['is_default' => true],
        [
            'canonical' => 'default',
            'description' => 'Default configuration',
        ]
    );

    $testQuote = 'NOCROSSTEST'.fake()->randomNumber(5);

    $accountBinance = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $binanceApiSystem->id,
        'trade_configuration_id' => $tradeConfig->id,
        'trading_quote' => $testQuote,
        'can_trade' => true,
    ]);

    $accountBybit = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $bybitApiSystem->id,
        'trade_configuration_id' => $tradeConfig->id,
        'trading_quote' => $testQuote,
        'can_trade' => true,
    ]);

    // Create BTC for both exchanges (required for token discovery)
    createBtcExchangeSymbol('LONG', '1h', $binanceApiSystem->id, $testQuote);
    createBtcExchangeSymbol('LONG', '1h', $bybitApiSystem->id, $testQuote);

    // Create a token on Binance first (required for Bybit symbol to have overlaps_with_binance=true)
    $tokenOnBinance = createExchangeSymbolWithData(
        'ALLOWTOKEN1',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $binanceApiSystem->id,
        $testQuote
    );

    // Active position on Binance account
    Position::factory()->create([
        'account_id' => $accountBinance->id,
        'exchange_symbol_id' => $tokenOnBinance->id,
        'status' => 'active',
        'direction' => 'LONG',
    ]);

    // Create the same token on Bybit (best scoring) - should be selected since flag is false
    $bestTokenBybit = createExchangeSymbolWithData(
        'ALLOWTOKEN1',
        'LONG',
        ['1h' => 0.95],
        ['1h' => 2.0],
        ['1h' => -1.5],
        $bybitApiSystem->id,
        $testQuote
    );

    createPositionSlot($accountBybit, 'LONG');

    $accountBybit->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $accountBybit->id)
        ->where('status', 'new')
        ->first();

    // Should select ALLOWTOKEN1 on Bybit (flag is false, no cross-account exclusion)
    expect($position)->not->toBeNull();
    expect($position->exchange_symbol_id)->toBe($bestTokenBybit->id);
});

test('excludes TokenMapper equivalent tokens across accounts', function (): void {
    // Create user with the flag enabled
    $user = Kraite\Core\Models\User::factory()->create([
        'have_distinct_position_tokens_on_all_accounts' => true,
    ]);

    $binanceApiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        ['name' => 'Binance', 'is_exchange' => true]
    );

    $bybitApiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'bybit'],
        ['name' => 'Bybit', 'is_exchange' => true]
    );

    $tradeConfig = TradeConfiguration::firstOrCreate(
        ['is_default' => true],
        [
            'canonical' => 'default',
            'description' => 'Default configuration',
        ]
    );

    $testQuote = 'MAPPERTEST'.fake()->randomNumber(5);

    $accountBinance = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $binanceApiSystem->id,
        'trade_configuration_id' => $tradeConfig->id,
        'trading_quote' => $testQuote,
        'can_trade' => true,
    ]);

    $accountBybit = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $bybitApiSystem->id,
        'trade_configuration_id' => $tradeConfig->id,
        'trading_quote' => $testQuote,
        'can_trade' => true,
    ]);

    // Create BTC for both exchanges (required for token discovery)
    createBtcExchangeSymbol('LONG', '1h', $binanceApiSystem->id, $testQuote);
    createBtcExchangeSymbol('LONG', '1h', $bybitApiSystem->id, $testQuote);

    // Create TokenMapper BEFORE creating symbols: Binance uses 1000FLOKI, Bybit uses FLOKI
    Kraite\Core\Models\TokenMapper::create([
        'binance_token' => '1000FLOKITEST',
        'other_token' => 'FLOKITEST',
        'other_api_system_id' => $bybitApiSystem->id,
    ]);

    // Create 1000FLOKITEST on Binance first (required for TokenMapper lookups)
    $flokiBinance = createExchangeSymbolWithData(
        '1000FLOKITEST',
        'LONG',
        ['1h' => 0.95],
        ['1h' => 2.0],
        ['1h' => -1.5],
        $binanceApiSystem->id,
        $testQuote
    );

    // Create OTHERTOKEN on Binance too (required for Bybit symbol to have overlaps_with_binance=true)
    createExchangeSymbolWithData(
        'OTHERTOKEN',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $binanceApiSystem->id,
        $testQuote
    );

    // Active position with 1000FLOKITEST on Binance
    Position::factory()->create([
        'account_id' => $accountBinance->id,
        'exchange_symbol_id' => $flokiBinance->id,
        'status' => 'active',
        'direction' => 'LONG',
    ]);

    // Create FLOKITEST on Bybit - should have overlaps_with_binance=true via TokenMapper
    // (observer checks if 1000FLOKITEST exists on Binance)
    createExchangeSymbolWithData(
        'FLOKITEST',
        'LONG',
        ['1h' => 0.95],
        ['1h' => 2.0],
        ['1h' => -1.5],
        $bybitApiSystem->id,
        $testQuote
    );

    // Create OTHERTOKEN on Bybit (available, should have overlaps_with_binance=true)
    $availableToken = createExchangeSymbolWithData(
        'OTHERTOKEN',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $bybitApiSystem->id,
        $testQuote
    );

    createPositionSlot($accountBybit, 'LONG');

    $accountBybit->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $accountBybit->id)
        ->where('status', 'new')
        ->first();

    // Should select OTHERTOKEN because FLOKITEST is equivalent to 1000FLOKITEST via TokenMapper
    expect($position)->not->toBeNull();
    expect($position->exchange_symbol_id)->toBe($availableToken->id);
});

test('excludes reverse TokenMapper equivalent tokens across accounts', function (): void {
    // Test the reverse: position on Bybit with FLOKI should exclude 1000FLOKI on Binance
    $user = Kraite\Core\Models\User::factory()->create([
        'have_distinct_position_tokens_on_all_accounts' => true,
    ]);

    $binanceApiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        ['name' => 'Binance', 'is_exchange' => true]
    );

    $bybitApiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'bybit'],
        ['name' => 'Bybit', 'is_exchange' => true]
    );

    $tradeConfig = TradeConfiguration::firstOrCreate(
        ['is_default' => true],
        [
            'canonical' => 'default',
            'description' => 'Default configuration',
        ]
    );

    $testQuote = 'REVMAPTEST'.fake()->randomNumber(5);

    $accountBybit = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $bybitApiSystem->id,
        'trade_configuration_id' => $tradeConfig->id,
        'trading_quote' => $testQuote,
        'can_trade' => true,
    ]);

    $accountBinance = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $binanceApiSystem->id,
        'trade_configuration_id' => $tradeConfig->id,
        'trading_quote' => $testQuote,
        'can_trade' => true,
    ]);

    // Create BTC for Binance account
    createBtcExchangeSymbol('LONG', '1h', $binanceApiSystem->id, $testQuote);

    // Create TokenMapper: Binance uses 1000PEPE, Bybit uses PEPE
    Kraite\Core\Models\TokenMapper::create([
        'binance_token' => '1000PEPETEST',
        'other_token' => 'PEPETEST',
        'other_api_system_id' => $bybitApiSystem->id,
    ]);

    // Create PEPE on Bybit with active position
    $pepeBybit = createExchangeSymbolWithData(
        'PEPETEST',
        'LONG',
        ['1h' => 0.95],
        ['1h' => 2.0],
        ['1h' => -1.5],
        $bybitApiSystem->id,
        $testQuote
    );

    Position::factory()->create([
        'account_id' => $accountBybit->id,
        'exchange_symbol_id' => $pepeBybit->id,
        'status' => 'active',
        'direction' => 'LONG',
    ]);

    // Create 1000PEPE on Binance (equivalent via TokenMapper) - should be excluded
    createExchangeSymbolWithData(
        '1000PEPETEST',
        'LONG',
        ['1h' => 0.95],
        ['1h' => 2.0],
        ['1h' => -1.5],
        $binanceApiSystem->id,
        $testQuote
    );

    // Create another token on Binance (available)
    $availableToken = createExchangeSymbolWithData(
        'AVAILPEPE',
        'LONG',
        ['1h' => 0.5],
        ['1h' => 1.0],
        ['1h' => -0.5],
        $binanceApiSystem->id,
        $testQuote
    );

    createPositionSlot($accountBinance, 'LONG');

    $accountBinance->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $accountBinance->id)
        ->where('status', 'new')
        ->first();

    // Should select AVAILPEPE because 1000PEPETEST is equivalent to PEPETEST via TokenMapper
    expect($position)->not->toBeNull();
    expect($position->exchange_symbol_id)->toBe($availableToken->id);
});

test('does not exclude tokens when user has no active positions on other accounts', function (): void {
    $user = Kraite\Core\Models\User::factory()->create([
        'have_distinct_position_tokens_on_all_accounts' => true,
    ]);

    $binanceApiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        ['name' => 'Binance', 'is_exchange' => true]
    );

    $tradeConfig = TradeConfiguration::firstOrCreate(
        ['is_default' => true],
        [
            'canonical' => 'default',
            'description' => 'Default configuration',
        ]
    );

    $testQuote = 'NOPOSTEST'.fake()->randomNumber(5);

    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $binanceApiSystem->id,
        'trade_configuration_id' => $tradeConfig->id,
        'trading_quote' => $testQuote,
        'can_trade' => true,
    ]);

    createBtcExchangeSymbol('LONG', '1h', $binanceApiSystem->id, $testQuote);

    // Create best scoring token - should be selected (no positions anywhere)
    $bestToken = createExchangeSymbolWithData(
        'BESTNOPOS',
        'LONG',
        ['1h' => 0.95],
        ['1h' => 2.0],
        ['1h' => -1.5],
        $binanceApiSystem->id,
        $testQuote
    );

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    // Should select BESTNOPOS (no exclusions)
    expect($position)->not->toBeNull();
    expect($position->exchange_symbol_id)->toBe($bestToken->id);
});

test('expandTokensWithMappings expands binance tokens to other tokens', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    $bybitApiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'bybit'],
        ['name' => 'Bybit', 'is_exchange' => true]
    );

    // Create mappings
    Kraite\Core\Models\TokenMapper::create([
        'binance_token' => 'EXPANDTEST1',
        'other_token' => 'EXPANDOTHER1',
        'other_api_system_id' => $bybitApiSystem->id,
    ]);

    $tokens = collect(['EXPANDTEST1', 'UNMAPPED']);

    $expanded = $account->expandTokensWithMappings($tokens);

    expect($expanded)->toContain('EXPANDTEST1');
    expect($expanded)->toContain('EXPANDOTHER1');
    expect($expanded)->toContain('UNMAPPED');
    expect($expanded->unique()->count())->toBe(3);
});

test('expandTokensWithMappings expands other tokens to binance tokens', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    $bybitApiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'bybit'],
        ['name' => 'Bybit', 'is_exchange' => true]
    );

    // Create mappings
    Kraite\Core\Models\TokenMapper::create([
        'binance_token' => 'BINANCEEXP',
        'other_token' => 'OTHEREXP',
        'other_api_system_id' => $bybitApiSystem->id,
    ]);

    $tokens = collect(['OTHEREXP']);

    $expanded = $account->expandTokensWithMappings($tokens);

    expect($expanded)->toContain('OTHEREXP');
    expect($expanded)->toContain('BINANCEEXP');
    expect($expanded->unique()->count())->toBe(2);
});

test('expandTokensWithMappings handles tokens with no mappings', function (): void {
    $account = createAccountForTokenDiscoveryTest();

    $tokens = collect(['NOMAPPING1', 'NOMAPPING2']);

    $expanded = $account->expandTokensWithMappings($tokens);

    expect($expanded)->toContain('NOMAPPING1');
    expect($expanded)->toContain('NOMAPPING2');
    expect($expanded->unique()->count())->toBe(2);
});
