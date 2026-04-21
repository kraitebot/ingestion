<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Models\ExchangeSymbol\DiscoverCMCTokenForExchangeSymbolJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Symbol;

/**
 * Helper to set up CMC API system for single token discovery tests
 */
function setupCmcApiSystemForSingleTokenDiscovery(): ApiSystem
{
    Kraite::first()->update([
        'coinmarketcap_api_key' => 'test-cmc-key',
    ]);

    return ApiSystem::factory()->create([
        'canonical' => 'coinmarketcap',
        'name' => 'CoinMarketCap',
        'is_exchange' => false,
    ]);
}

/**
 * Helper to create an orphaned exchange symbol for single token discovery testing
 */
function createOrphanedExchangeSymbolForSingleTokenDiscovery(#[SensitiveParameter] string $token, string $quote = 'USDT'): ExchangeSymbol
{
    $exchangeApiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance_test_'.uniqid(),
        'name' => 'Binance Test',
    ]);

    return ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => $quote,
        'api_system_id' => $exchangeApiSystem->id,
        'symbol_id' => null,
        'api_statuses' => [
            'cmc_api_called' => false,
            'taapi_verified' => false,
            'has_taapi_data' => false,
        ],
    ]);
}

test('links exchange symbol via direct database match', function () {
    setupCmcApiSystemForSingleTokenDiscovery();

    // Create symbol in DB
    $btcSymbol = Symbol::factory()->create(['token' => 'BTC', 'cmc_id' => 1]);

    // Create orphaned exchange symbol with same token
    $exchangeSymbol = createOrphanedExchangeSymbolForSingleTokenDiscovery('BTC');

    $job = new DiscoverCMCTokenForExchangeSymbolJob($exchangeSymbol->id);
    $result = $job->computeApiable();

    expect($result['status'])->toBe('linked');
    expect($result['source'])->toBe('database_lookup');
    expect($result['matched_to'])->toBe('BTC');
    expect($result['message'])->toContain('direct match');

    // Verify exchange symbol was linked
    $exchangeSymbol->refresh();
    expect($exchangeSymbol->symbol_id)->toBe($btcSymbol->id);
});

test('strips numeric prefix to find match (1000SHIB -> SHIB)', function () {
    setupCmcApiSystemForSingleTokenDiscovery();

    // Create SHIB symbol
    $shibSymbol = Symbol::factory()->create(['token' => 'SHIB', 'cmc_id' => 5994]);

    // Create orphaned exchange symbol with numeric prefix
    $exchangeSymbol = createOrphanedExchangeSymbolForSingleTokenDiscovery('1000SHIB');

    $job = new DiscoverCMCTokenForExchangeSymbolJob($exchangeSymbol->id);
    $result = $job->computeApiable();

    expect($result['status'])->toBe('linked');
    expect($result['source'])->toBe('database_lookup');
    expect($result['matched_to'])->toBe('SHIB');
    expect($result['matched_via'])->toBe('SHIB');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->symbol_id)->toBe($shibSymbol->id);
});

test('uses hardcoded alias to find match (XBT -> BTC)', function () {
    setupCmcApiSystemForSingleTokenDiscovery();

    // Create BTC symbol
    $btcSymbol = Symbol::factory()->create(['token' => 'BTC', 'cmc_id' => 1]);

    // Create orphaned exchange symbol with XBT alias (used by some exchanges for BTC)
    $exchangeSymbol = createOrphanedExchangeSymbolForSingleTokenDiscovery('XBT');

    $job = new DiscoverCMCTokenForExchangeSymbolJob($exchangeSymbol->id);
    $result = $job->computeApiable();

    expect($result['status'])->toBe('linked');
    expect($result['source'])->toBe('database_lookup');
    expect($result['matched_to'])->toBe('BTC');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->symbol_id)->toBe($btcSymbol->id);
});

test('strips 1M prefix to find match (1MBABYDOGE -> BABYDOGE)', function () {
    setupCmcApiSystemForSingleTokenDiscovery();

    $babyDogeSymbol = Symbol::factory()->create(['token' => 'BABYDOGE', 'cmc_id' => 10407]);

    $exchangeSymbol = createOrphanedExchangeSymbolForSingleTokenDiscovery('1MBABYDOGE');

    $job = new DiscoverCMCTokenForExchangeSymbolJob($exchangeSymbol->id);
    $result = $job->computeApiable();

    expect($result['status'])->toBe('linked');
    expect($result['matched_to'])->toBe('BABYDOGE');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->symbol_id)->toBe($babyDogeSymbol->id);
});

test('strips trailing numbers to find match (SHIB1000 -> SHIB)', function () {
    setupCmcApiSystemForSingleTokenDiscovery();

    $shibSymbol = Symbol::factory()->create(['token' => 'SHIB', 'cmc_id' => 5994]);

    $exchangeSymbol = createOrphanedExchangeSymbolForSingleTokenDiscovery('SHIB1000');

    $job = new DiscoverCMCTokenForExchangeSymbolJob($exchangeSymbol->id);
    $result = $job->computeApiable();

    expect($result['status'])->toBe('linked');
    expect($result['matched_to'])->toBe('SHIB');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->symbol_id)->toBe($shibSymbol->id);
});

test('strips W prefix for wrapped tokens (WBTC -> BTC)', function () {
    setupCmcApiSystemForSingleTokenDiscovery();

    $btcSymbol = Symbol::factory()->create(['token' => 'BTC', 'cmc_id' => 1]);

    $exchangeSymbol = createOrphanedExchangeSymbolForSingleTokenDiscovery('WBTC');

    $job = new DiscoverCMCTokenForExchangeSymbolJob($exchangeSymbol->id);
    $result = $job->computeApiable();

    expect($result['status'])->toBe('linked');
    expect($result['matched_to'])->toBe('BTC');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->symbol_id)->toBe($btcSymbol->id);
});

test('strips st prefix for staked tokens (stETH -> ETH)', function () {
    setupCmcApiSystemForSingleTokenDiscovery();

    $ethSymbol = Symbol::factory()->create(['token' => 'ETH', 'cmc_id' => 1027]);

    $exchangeSymbol = createOrphanedExchangeSymbolForSingleTokenDiscovery('STETH');

    $job = new DiscoverCMCTokenForExchangeSymbolJob($exchangeSymbol->id);
    $result = $job->computeApiable();

    expect($result['status'])->toBe('linked');
    expect($result['matched_to'])->toBe('ETH');

    $exchangeSymbol->refresh();
    expect($exchangeSymbol->symbol_id)->toBe($ethSymbol->id);
});

test('skips if exchange symbol already has symbol_id', function () {
    setupCmcApiSystemForSingleTokenDiscovery();

    $existingSymbol = Symbol::factory()->create(['token' => 'BTC']);

    $exchangeSymbol = createOrphanedExchangeSymbolForSingleTokenDiscovery('BTC');
    $exchangeSymbol->update(['symbol_id' => $existingSymbol->id]);

    $job = new DiscoverCMCTokenForExchangeSymbolJob($exchangeSymbol->id);

    // startOrSkip returns false so the step transitions to Skipped rather
    // than Failed when a sibling job has already linked this symbol
    // (renamed from startOrFail in kraitebot/core v1.3.6).
    expect($job->startOrSkip())->toBeFalse();
});

test('generates correct token candidates for complex token names', function () {
    setupCmcApiSystemForSingleTokenDiscovery();

    // Test with 1000000BABYDOGE -> should try multiple candidates
    $babyDogeSymbol = Symbol::factory()->create(['token' => 'BABYDOGE', 'cmc_id' => 10407]);

    $exchangeSymbol = createOrphanedExchangeSymbolForSingleTokenDiscovery('1000000BABYDOGE');

    $job = new DiscoverCMCTokenForExchangeSymbolJob($exchangeSymbol->id);
    $result = $job->computeApiable();

    expect($result['status'])->toBe('linked');
    expect($result['matched_to'])->toBe('BABYDOGE');
});

test('handles .P suffix for perpetual contracts (BTC.P -> BTC)', function () {
    setupCmcApiSystemForSingleTokenDiscovery();

    $btcSymbol = Symbol::factory()->create(['token' => 'BTC', 'cmc_id' => 1]);

    $exchangeSymbol = createOrphanedExchangeSymbolForSingleTokenDiscovery('BTC.P');

    $job = new DiscoverCMCTokenForExchangeSymbolJob($exchangeSymbol->id);
    $result = $job->computeApiable();

    expect($result['status'])->toBe('linked');
    expect($result['matched_to'])->toBe('BTC');
});

test('strips PERP suffix (BTCPERP -> BTC)', function () {
    setupCmcApiSystemForSingleTokenDiscovery();

    $btcSymbol = Symbol::factory()->create(['token' => 'BTC', 'cmc_id' => 1]);

    $exchangeSymbol = createOrphanedExchangeSymbolForSingleTokenDiscovery('BTCPERP');

    $job = new DiscoverCMCTokenForExchangeSymbolJob($exchangeSymbol->id);
    $result = $job->computeApiable();

    expect($result['status'])->toBe('linked');
    expect($result['matched_to'])->toBe('BTC');
});

test('handles extended X-prefix aliases for BTC (XXBT)', function () {
    setupCmcApiSystemForSingleTokenDiscovery();

    // Test XXBT -> BTC
    $btcSymbol = Symbol::factory()->create(['token' => 'BTC', 'cmc_id' => 1]);

    $exchangeSymbol = createOrphanedExchangeSymbolForSingleTokenDiscovery('XXBT');

    $job = new DiscoverCMCTokenForExchangeSymbolJob($exchangeSymbol->id);
    $result = $job->computeApiable();

    expect($result['status'])->toBe('linked');
    expect($result['matched_to'])->toBe('BTC');
});

test('returns eloquent message for direct match', function () {
    setupCmcApiSystemForSingleTokenDiscovery();

    $btcSymbol = Symbol::factory()->create(['token' => 'BTC', 'cmc_id' => 1]);
    $exchangeSymbol = createOrphanedExchangeSymbolForSingleTokenDiscovery('BTC');

    $job = new DiscoverCMCTokenForExchangeSymbolJob($exchangeSymbol->id);
    $result = $job->computeApiable();

    expect($result['message'])->toBe('Linked BTC → BTC (direct match)');
});

test('returns eloquent message for indirect match', function () {
    setupCmcApiSystemForSingleTokenDiscovery();

    $btcSymbol = Symbol::factory()->create(['token' => 'BTC', 'cmc_id' => 1]);
    $exchangeSymbol = createOrphanedExchangeSymbolForSingleTokenDiscovery('XBT');

    $job = new DiscoverCMCTokenForExchangeSymbolJob($exchangeSymbol->id);
    $result = $job->computeApiable();

    expect($result['message'])->toBe('Linked XBT → BTC (via BTC)');
});
