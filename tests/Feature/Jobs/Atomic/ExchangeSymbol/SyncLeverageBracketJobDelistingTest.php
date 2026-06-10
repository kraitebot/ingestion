<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Kraite\Core\Jobs\Atomic\ExchangeSymbol\SyncLeverageBracketJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;

/**
 * The per-symbol leverage-bracket atomic must isolate a single dead symbol.
 *
 * When the exchange reports a symbol closed/invalid at runtime (Bybit retCode
 * 10001 "symbol is closed or invalid" / "Not supported symbols", and the
 * per-exchange equivalents), the atomic must mark it for delisting and
 * COMPLETE — it must never throw. A throw fails the shared parent
 * SyncLeverageBracketsJob ("Child step(s) failed") and, because the symbol is
 * never flagged, recurs on every hourly refresh until cleaned up by hand.
 *
 * 2026-06-09: AAOI/USDT on Bybit did exactly this — is_marked_for_delisting=0,
 * Bybit answered "symbol is closed or invalid", the atomic threw, and the Bybit
 * leverage parent failed every cycle (archive: 06-04, 06-06, 06-09) even though
 * all 636 sibling symbols updated fine. The self-heal already lived in
 * FetchKlinesJob; it was missing from the leverage atomic. This regression
 * locks the parity in. The fix is exchange-agnostic (isSymbolDelisted is
 * implemented per exchange), so the shared atomic covers Bybit, KuCoin, Bitget.
 *
 * Drives the real API pipeline (Http::fake → the exchange's HTTP-200-with-error
 * handler throws), so the test exercises the exact path that failed in prod.
 */
function setupBybitLeverageAtomic(string $retMsg): array
{
    Kraite::updateOrCreate(['id' => 1], [
        'email' => 'admin@test.com',
        'bybit_api_key' => 'TESTKEY',
        'bybit_api_secret' => 'TESTSECRET',
        'notification_channels' => ['mail'],
        'allow_opening_positions' => true,
    ]);

    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bybit',
        'name' => 'Bybit',
    ]);

    $symbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'token' => 'AAOI',
        'quote' => 'USDT',
        'asset' => 'AAOIUSDT',
        'is_marked_for_delisting' => false,
    ]);

    // Bybit answers HTTP 200 with retCode 10001 for a closed/removed symbol;
    // the BybitExceptionHandler turns that into a thrown RequestException.
    Http::fake([
        '*' => Http::response(['retCode' => 10001, 'retMsg' => $retMsg, 'result' => []], 200),
    ]);

    $job = new SyncLeverageBracketJob($symbol->id);
    $job->assignExceptionHandler();

    return [$job, $symbol];
}

it('marks the symbol for delisting and completes when the exchange reports it closed or invalid', function (): void {
    [$job, $symbol] = setupBybitLeverageAtomic('Not supported symbols');

    $result = $job->computeApiable();

    expect($result['delisted'] ?? false)->toBeTrue()
        ->and($symbol->fresh()->is_marked_for_delisting)->toBeTrue();
});

it('rethrows non-delisting exchange errors so genuine failures still surface', function (): void {
    [$job, $symbol] = setupBybitLeverageAtomic('orderLinkId is required');

    expect(fn () => $job->computeApiable())->toThrow(Exception::class);
    expect($symbol->fresh()->is_marked_for_delisting)->toBeFalse();
});
