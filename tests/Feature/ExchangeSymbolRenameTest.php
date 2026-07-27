<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Models\ExchangeSymbol\DiscoverCMCTokenForExchangeSymbolJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Notifications\AlertNotification;

/**
 * Exchange-symbol rename lifecycle (TON → GRAM, 2026-07).
 *
 * A rename is detected at the identity-link moment: a freshly created
 * listing links to the same coin identity as a recently vanished sibling
 * on the same exchange and quote. The old listing is retired with the
 * one-way `renamed` block, the successor records its ancestry, and the
 * retired row becomes a sealed archive invisible to every pipeline —
 * most critically to the backtesting fan-out, whose rejected verdict
 * would otherwise expose the archive's irreplaceable pre-rename candles
 * to the rejected-symbols purge.
 */
beforeEach(function (): void {
    Notification::fake();

    // The suite runs with notifications globally disabled; the rename
    // alerts are part of the behaviour under test, so enable the master
    // switch (config fallback AND the singleton column when present).
    config(['kraite.notifications_enabled' => true]);
    Kraite::query()->update(['notifications_enabled' => true]);
});

/**
 * Build a coin with an old (vanished) listing and a new orphan listing
 * on the same exchange + quote, ready for discovery to link and detect.
 *
 * @return array{symbol: Symbol, old: ExchangeSymbol, new: ExchangeSymbol, apiSystem: ApiSystem}
 */
function buildRenameWorld(string $suffix, string $quote = 'USDT'): array
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
        'is_active' => true,
    ]);

    // Catalogue label is already current (post-reconcile state): the
    // catalogue says NEWT…, so the new listing's name-lookup matches.
    $symbol = Symbol::factory()->create([
        'token' => 'NEWT'.$suffix,
        'name' => 'Renamed Coin '.$suffix,
        'cmc_id' => crc32($suffix) % 900000 + 100000,
        'cmc_ranking' => 50,
    ]);

    $old = ExchangeSymbol::factory()->create([
        'token' => 'OLDT'.$suffix,
        'quote' => $quote,
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'is_marked_for_delisting' => false,
        'was_backtesting_approved' => true,
        'backtesting_review_status' => 'approved',
    ]);

    // Flip the flag through the model so the audit trail records the
    // moment the exchange feed dropped the ticker — the recency source
    // the detector trusts.
    $old->updateSaving(['is_marked_for_delisting' => true]);

    $new = ExchangeSymbol::factory()->create([
        'token' => 'NEWT'.$suffix,
        'quote' => $quote,
        'api_system_id' => $apiSystem->id,
        'symbol_id' => null,
        'is_marked_for_delisting' => false,
    ]);

    return ['symbol' => $symbol, 'old' => $old, 'new' => $new, 'apiSystem' => $apiSystem];
}

function backdateDelistingAudit(ExchangeSymbol $exchangeSymbol, int $days): void
{
    DB::table('model_logs')
        ->where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $exchangeSymbol->id)
        ->where('attribute_name', 'is_marked_for_delisting')
        ->where('new_value', '1')
        ->update(['created_at' => now()->subDays($days)]);
}

function assertRenameAlertSent(string $canonical): void
{
    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        static function (AlertNotification $notification) use ($canonical): bool {
            return $notification->canonical === $canonical;
        }
    );
}

function assertRenameAlertNotSent(string $canonical): void
{
    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        static function (AlertNotification $notification) use ($canonical): bool {
            return $notification->canonical === $canonical;
        }
    );
}

it('retires the old listing and threads the successor when a rename lands inside the window', function (): void {
    $suffix = mb_strtoupper(Str::random(4));
    $world = buildRenameWorld($suffix);

    // Unrelated active listing on the same exchange must stay untouched.
    $bystanderSymbol = Symbol::factory()->create(['token' => 'BYST'.$suffix, 'cmc_id' => 999001]);
    $bystander = ExchangeSymbol::factory()->create([
        'token' => 'BYST'.$suffix,
        'quote' => 'USDT',
        'api_system_id' => $world['apiSystem']->id,
        'symbol_id' => $bystanderSymbol->id,
    ]);

    expect($world['old']->fresh()->system_disabled_at)->toBeNull()
        ->and($world['new']->fresh()->renamed_from_exchange_symbol_id)->toBeNull();

    $result = (new DiscoverCMCTokenForExchangeSymbolJob($world['new']->id))->computeApiable();

    $old = $world['old']->fresh();
    $new = $world['new']->fresh();

    expect($result['status'])->toBe('linked')
        ->and($result['rename']['status'])->toBe('renamed')
        ->and($result['rename']['retired_id'])->toBe($old->id)
        ->and($new->symbol_id)->toBe($world['symbol']->id)
        ->and($new->renamed_from_exchange_symbol_id)->toBe($old->id)
        ->and($old->system_disabled_reason)->toBe(ExchangeSymbol::SYSTEM_BLOCK_RENAMED)
        ->and($old->system_disabled_at)->not->toBeNull()
        ->and($bystander->fresh()->system_disabled_at)->toBeNull();

    assertRenameAlertSent('exchange_symbol_renamed');
    assertRenameAlertNotSent('exchange_symbol_rename_suspected');
});

it('only reports a suspected rename when the old listing vanished outside the window', function (): void {
    $suffix = mb_strtoupper(Str::random(4));
    $world = buildRenameWorld($suffix);
    backdateDelistingAudit($world['old'], days: 30);

    $result = (new DiscoverCMCTokenForExchangeSymbolJob($world['new']->id))->computeApiable();

    $old = $world['old']->fresh();
    $new = $world['new']->fresh();

    expect($result['rename']['status'])->toBe('suspected_only')
        ->and($new->symbol_id)->toBe($world['symbol']->id)
        ->and($new->renamed_from_exchange_symbol_id)->toBeNull()
        ->and($old->system_disabled_at)->toBeNull()
        ->and($old->system_disabled_reason)->toBeNull();

    assertRenameAlertSent('exchange_symbol_rename_suspected');
    assertRenameAlertNotSent('exchange_symbol_renamed');
});

it('treats a missing delisting audit record as outside the window', function (): void {
    $suffix = mb_strtoupper(Str::random(4));
    $world = buildRenameWorld($suffix);

    DB::table('model_logs')
        ->where('loggable_type', ExchangeSymbol::class)
        ->where('loggable_id', $world['old']->id)
        ->where('attribute_name', 'is_marked_for_delisting')
        ->delete();

    $result = (new DiscoverCMCTokenForExchangeSymbolJob($world['new']->id))->computeApiable();

    expect($result['rename']['status'])->toBe('suspected_only')
        ->and($world['old']->fresh()->system_disabled_at)->toBeNull()
        ->and($world['new']->fresh()->renamed_from_exchange_symbol_id)->toBeNull();
});

it('does not treat a live sibling as a rename', function (): void {
    $suffix = mb_strtoupper(Str::random(4));
    $world = buildRenameWorld($suffix);

    // The old listing is still live on the exchange (multiplier-variant
    // coexistence: PEPE active while 1000PEPE lists).
    $world['old']->updateSaving(['is_marked_for_delisting' => false]);

    $result = (new DiscoverCMCTokenForExchangeSymbolJob($world['new']->id))->computeApiable();

    expect($result['status'])->toBe('linked')
        ->and($result['rename'])->toBeNull()
        ->and($world['old']->fresh()->system_disabled_at)->toBeNull()
        ->and($world['new']->fresh()->renamed_from_exchange_symbol_id)->toBeNull();

    assertRenameAlertNotSent('exchange_symbol_renamed');
    assertRenameAlertNotSent('exchange_symbol_rename_suspected');
});

it('ignores a delisted sibling on a different quote currency', function (): void {
    $suffix = mb_strtoupper(Str::random(4));
    $world = buildRenameWorld($suffix, quote: 'USDC');

    // New listing arrives on USDT while the vanished one traded USDC.
    $world['new']->updateQuietly(['quote' => 'USDT']);

    $result = (new DiscoverCMCTokenForExchangeSymbolJob($world['new']->id))->computeApiable();

    expect($result['rename'])->toBeNull()
        ->and($world['old']->fresh()->system_disabled_at)->toBeNull();
});

it('does not re-process an already handled rename', function (): void {
    $suffix = mb_strtoupper(Str::random(4));
    $world = buildRenameWorld($suffix);

    (new DiscoverCMCTokenForExchangeSymbolJob($world['new']->id))->computeApiable();

    $old = $world['old']->fresh();
    expect($old->system_disabled_reason)->toBe(ExchangeSymbol::SYSTEM_BLOCK_RENAMED);
    $retiredAt = $old->system_disabled_at;

    // The dispatcher-level gate: a linked successor never re-enters
    // discovery (parallel-job dedupe path).
    expect((new DiscoverCMCTokenForExchangeSymbolJob($world['new']->id))->startOrSkip())->toBeFalse();

    // Belt-and-braces: even a forced re-run finds the sibling already
    // retired and does nothing — no double block, no second thread.
    $result = (new DiscoverCMCTokenForExchangeSymbolJob($world['new']->id))->computeApiable();

    expect($result['rename'])->toBeNull()
        ->and($world['old']->fresh()->system_disabled_at?->toDateTimeString())
        ->toBe($retiredAt?->toDateTimeString(), 'The retirement timestamp must not be rewritten.');
});

it('keeps the retired listing frozen when the successor is rejected — the purge cannot reach the archive', function (): void {
    $suffix = mb_strtoupper(Str::random(4));
    $world = buildRenameWorld($suffix);

    (new DiscoverCMCTokenForExchangeSymbolJob($world['new']->id))->computeApiable();

    $old = $world['old']->fresh();
    $new = $world['new']->fresh();

    // A sibling on another exchange still receives the fan-out.
    $otherExchange = ApiSystem::factory()->exchange()->create(['canonical' => 'bybit', 'name' => 'Bybit']);
    $sibling = ExchangeSymbol::factory()->create([
        'token' => 'NEWT'.$suffix,
        'quote' => 'USDT',
        'api_system_id' => $otherExchange->id,
        'symbol_id' => $world['symbol']->id,
        'was_backtesting_approved' => true,
        'backtesting_review_status' => 'approved',
    ]);

    // The archive owns candles nothing can re-download.
    $archiveCandle = Candle::factory()->create(['exchange_symbol_id' => $old->id]);
    $successorCandle = Candle::factory()->create(['exchange_symbol_id' => $new->id]);

    expect($old->backtesting_review_status)->toBe('approved');

    // The successor gets rejected — the normal outcome for a freshly
    // renamed coin with days of provider history.
    $new->update([
        'was_backtesting_approved' => false,
        'backtesting_review_status' => 'rejected',
    ]);

    expect($old->fresh()->backtesting_review_status)
        ->toBe('approved', 'The fan-out must never flip the sealed archive to rejected.')
        ->and($old->fresh()->was_backtesting_approved)->toBeTrue()
        ->and($sibling->fresh()->backtesting_review_status)
        ->toBe('rejected', 'Live siblings must still receive the fan-out.');

    $this->artisan('kraite:cron-purge-failed-backtested-klines')->assertExitCode(0);

    expect(Candle::query()->whereKey($archiveCandle->id)->exists())
        ->toBeTrue('The archive candles must survive the rejected-symbols purge.')
        ->and(Candle::query()->whereKey($successorCandle->id)->exists())
        ->toBeFalse('The rejected successor loses only refetchable post-rename candles.');
});

it('hides renamed listings from the pipeline selections', function (): void {
    $suffix = mb_strtoupper(Str::random(4));
    $world = buildRenameWorld($suffix);

    (new DiscoverCMCTokenForExchangeSymbolJob($world['new']->id))->computeApiable();

    $old = $world['old']->fresh();
    $new = $world['new']->fresh();

    $old->updateQuietly(['api_statuses' => ['has_taapi_data' => true], 'delivery_at' => null]);
    $new->updateQuietly(['api_statuses' => ['has_taapi_data' => true], 'delivery_at' => null]);

    $indicatorIds = ExchangeSymbol::needsIndicatorAttempt()->pluck('exchange_symbols.id');
    $monitoringIds = ExchangeSymbol::query()->needsOperationalMonitoring()->pluck('exchange_symbols.id');

    expect($indicatorIds->contains($new->id))->toBeTrue()
        ->and($indicatorIds->contains($old->id))->toBeFalse('Renamed rows must not reach the direction workflow.')
        ->and($monitoringIds->contains($new->id))->toBeTrue()
        ->and($monitoringIds->contains($old->id))->toBeFalse('Renamed rows must not reach the candle fetcher.')
        ->and($new->getOthersFromExchanges()->pluck('id')->contains($old->id))
        ->toBeFalse('Sibling walks must skip the sealed archive.');
});
