<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Kraite\Core\Models\Position;
use StepDispatcher\Models\StepsDispatcher;

/*
 * Test-only god-mode override at config('kraite.position_creation.symbol_override').
 * Forces a specific symbol on a configured account regardless of scoring,
 * eligibility gates, or correlation/elasticity. Falls back silently
 * to the normal selection algorithm when:
 *   - config is null,
 *   - account_id does not match,
 *   - the symbol cannot be resolved on the account's exchange,
 *   - the symbol is already open as an active position on the account,
 *   - the resolved symbol's direction does not match the slot's direction.
 *
 * Expected wiring: top of HasTokenDiscovery::assignTokensToPositions(),
 * one resolver call per slot, before the fast-tracked / BTC-bias paths.
 */

beforeEach(function () {
    StepsDispatcher::updateOrCreate(['group' => 'alpha'], ['can_dispatch' => true]);
    StepsDispatcher::updateOrCreate(['group' => 'beta'], ['can_dispatch' => true]);

    Config::set('kraite.token_discovery.correlation_type', 'pearson');
    Config::set('kraite.token_discovery.btc_biased_restriction', true);
    Config::set('kraite.token_discovery.require_matching_correlation_sign', true);
    Config::set('kraite.correlation.btc_token', 'BTC');

    // Default: no override configured.
    Config::set('kraite.position_creation.symbol_override', null);
});

test('override forces a specific symbol when account_id matches and symbol resolves', function () {
    $account = createAccountForTokenDiscoveryTest();
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Two LONG candidates — high-score "WINNER" would normally be picked.
    createExchangeSymbolWithData(
        'WINNER',
        'LONG',
        ['1h' => 0.9],
        ['1h' => 2.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // The override target — would lose by score in the normal algorithm.
    $override = createExchangeSymbolWithData(
        'OVERRIDEN',
        'LONG',
        ['1h' => 0.1],
        ['1h' => 0.1],
        ['1h' => -0.1],
        $account->api_system_id,
        $account->trading_quote
    );

    $tradingPair = $override->parsed_trading_pair; // e.g. "OVERRIDENTESTQUOTE..."

    Config::set('kraite.position_creation.symbol_override', [
        'account_id' => $account->id,
        'symbol' => $tradingPair,
    ]);

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    expect($position)->not->toBeNull();
    expect($position->exchange_symbol_id)->toBe($override->id);
});

test('override falls back to normal scoring when config is null', function () {
    $account = createAccountForTokenDiscoveryTest();
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    $best = createExchangeSymbolWithData(
        'BEST',
        'LONG',
        ['1h' => 0.9],
        ['1h' => 2.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    createExchangeSymbolWithData(
        'WORSE',
        'LONG',
        ['1h' => 0.1],
        ['1h' => 0.1],
        ['1h' => -0.1],
        $account->api_system_id,
        $account->trading_quote
    );

    // Config explicitly null — overall behaviour must match the no-override path.
    Config::set('kraite.position_creation.symbol_override', null);

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)->where('status', 'new')->first();

    expect($position->exchange_symbol_id)->toBe($best->id);
});

test('override falls back to normal scoring when account_id does not match', function () {
    $account = createAccountForTokenDiscoveryTest();
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    $best = createExchangeSymbolWithData(
        'BESTSCORE',
        'LONG',
        ['1h' => 0.9],
        ['1h' => 2.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    $overrideTarget = createExchangeSymbolWithData(
        'WOULDBEFORCED',
        'LONG',
        ['1h' => 0.1],
        ['1h' => 0.1],
        ['1h' => -0.1],
        $account->api_system_id,
        $account->trading_quote
    );

    // Override is for a different account → must be ignored for this one.
    Config::set('kraite.position_creation.symbol_override', [
        'account_id' => $account->id + 999_999,
        'symbol' => $overrideTarget->parsed_trading_pair,
    ]);

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)->where('status', 'new')->first();

    expect($position->exchange_symbol_id)->toBe($best->id);
});

test('override falls back silently when symbol cannot be resolved on the account exchange', function () {
    $account = createAccountForTokenDiscoveryTest();
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    $best = createExchangeSymbolWithData(
        'REGULAR',
        'LONG',
        ['1h' => 0.9],
        ['1h' => 2.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    // Override points to a symbol that doesn't exist on this exchange.
    Config::set('kraite.position_creation.symbol_override', [
        'account_id' => $account->id,
        'symbol' => 'GHOSTPAIR'.$account->trading_quote,
    ]);

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)->where('status', 'new')->first();

    // Silent fallback: no exception, normal scoring still picks REGULAR.
    expect($position->exchange_symbol_id)->toBe($best->id);
});

test('override falls back silently when symbol already has an active position on the account', function () {
    $account = createAccountForTokenDiscoveryTest();
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    $alreadyOpen = createExchangeSymbolWithData(
        'OPENED',
        'LONG',
        ['1h' => 0.1],
        ['1h' => 0.1],
        ['1h' => -0.1],
        $account->api_system_id,
        $account->trading_quote
    );

    // The "already open" precondition: an active position on this account
    // pinned to the override symbol.
    Position::factory()->create([
        'account_id' => $account->id,
        'uuid' => fake()->uuid(),
        'exchange_symbol_id' => $alreadyOpen->id,
        'parsed_trading_pair' => $alreadyOpen->parsed_trading_pair,
        'status' => 'active',
        'direction' => 'LONG',
        'opened_at' => now()->subMinutes(30),
    ]);

    $alternative = createExchangeSymbolWithData(
        'ALTERNATIVE',
        'LONG',
        ['1h' => 0.9],
        ['1h' => 2.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    Config::set('kraite.position_creation.symbol_override', [
        'account_id' => $account->id,
        'symbol' => $alreadyOpen->parsed_trading_pair,
    ]);

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $newSlotPosition = Position::where('account_id', $account->id)
        ->where('status', 'new')
        ->first();

    // Override blocked because already open → normal scoring picks the alternative.
    expect($newSlotPosition->exchange_symbol_id)->toBe($alternative->id);
});

test('override falls back silently when symbol direction does not match the slot direction', function () {
    $account = createAccountForTokenDiscoveryTest();
    createBtcExchangeSymbol('LONG', '1h', $account->api_system_id, $account->trading_quote);

    // Override points to a SHORT-direction symbol, but the slot is LONG.
    $shortOverride = createExchangeSymbolWithData(
        'SHORTONLY',
        'SHORT',
        ['1h' => -0.5],
        ['1h' => 0.1],
        ['1h' => -1.0],
        $account->api_system_id,
        $account->trading_quote
    );

    $longCandidate = createExchangeSymbolWithData(
        'LONGCANDIDATE',
        'LONG',
        ['1h' => 0.9],
        ['1h' => 2.0],
        ['1h' => -0.5],
        $account->api_system_id,
        $account->trading_quote
    );

    Config::set('kraite.position_creation.symbol_override', [
        'account_id' => $account->id,
        'symbol' => $shortOverride->parsed_trading_pair,
    ]);

    createPositionSlot($account, 'LONG');

    $account->assignBestTokenToNewPositions();

    $position = Position::where('account_id', $account->id)->where('status', 'new')->first();

    // Silent fallback: direction mismatch → normal scoring picks LONGCANDIDATE.
    expect($position->exchange_symbol_id)->toBe($longCandidate->id);
});
