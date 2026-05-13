<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use Kraite\Core\Notifications\AlertNotification;

/**
 * Pin the never-synced mark-price branch on `CheckSystemHealthCommand`.
 *
 * Pre-fix, `checkMarkPriceFreshness()` only alerted on rows whose
 * `mark_price_synced_at` was BOTH non-null AND older than the staleness
 * threshold. A symbol whose sidecar row was never written to (NULL
 * timestamp) was silently ignored — yet `HasTokenDiscovery` line
 * 293-295 treats null timestamps as "pass" the freshness gate, so a
 * never-synced symbol is selectable for slots while having no live
 * price. Position-sizing math then divides notional by null-mark_price.
 *
 * Post-fix, a second branch alerts on `mark_price_synced_at IS NULL`
 * past `MARK_PRICE_MISSING_GRACE_MINUTES`. Distinct signal name
 * (`mark_price_missing_{pair}`) lets ops triage "never synced" (likely
 * pair-mapping issue in the daemon) vs "stale" (likely daemon down).
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['kraite.notifications_enabled' => true]);
    Notification::fake();
    Illuminate\Support\Once::flush();

    Kraite::firstOrCreate(
        ['id' => 1],
        [
            'email' => 'admin@test.com',
            'admin_pushover_user_key' => 'k',
            'admin_pushover_application_key' => 'a',
            'notification_channels' => ['mail'],
        ]
    );
});

/**
 * Build an ExchangeSymbol that is "eligible" via the open-position
 * branch of `eligibleExchangeSymbolsQuery()`. Bypasses the brutal
 * tradeable() scope (which requires TAAPI data, correlation, leverage
 * brackets, etc.) by attaching an open position — that alone makes
 * the symbol eligible for the freshness check.
 */
function makeEligibleSymbolWithOpenPosition(string $token, string $quote = 'USDT'): ExchangeSymbol
{
    $symbol = ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => $quote,
        'is_marked_for_delisting' => false,
        'delivery_at' => null,
    ]);

    Position::factory()->create([
        'exchange_symbol_id' => $symbol->id,
        'status' => 'active',
    ]);

    return $symbol;
}

it('does not fire mark_price_missing when the sidecar timestamp is non-null', function (): void {
    $symbol = makeEligibleSymbolWithOpenPosition('FRESH');

    DB::table('exchange_symbol_prices')
        ->where('exchange_symbol_id', $symbol->id)
        ->update([
            'mark_price' => '100.00',
            'mark_price_synced_at' => now(),
            'created_at' => now()->subMinutes(30),
        ]);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'never synced')
    );
});

it('does not fire mark_price_missing when the sidecar is null but still inside the grace window', function (): void {
    $symbol = makeEligibleSymbolWithOpenPosition('GRACE');

    // Sidecar was created 2 minutes ago — within the 5-minute grace
    // window. The price daemon's pair-map refresh runs every 5 min, so
    // a brand-new symbol legitimately sits at null until the next
    // refresh tick. No alert.
    DB::table('exchange_symbol_prices')
        ->where('exchange_symbol_id', $symbol->id)
        ->update([
            'mark_price' => null,
            'mark_price_synced_at' => null,
            'created_at' => now()->subMinutes(2),
        ]);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'never synced')
    );
});

it('fires mark_price_missing when the sidecar is null past the grace window', function (): void {
    $symbol = makeEligibleSymbolWithOpenPosition('MISSING');

    // Sidecar created 10 minutes ago — past the 5-minute grace window.
    // The daemon should have written for this symbol by now. Either
    // pair-name mismatch in the pairToIds map or daemon was offline
    // through the entire onboarding window. Both cases are real.
    DB::table('exchange_symbol_prices')
        ->where('exchange_symbol_id', $symbol->id)
        ->update([
            'mark_price' => null,
            'mark_price_synced_at' => null,
            'created_at' => now()->subMinutes(10),
        ]);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => ($n->canonical ?? '') === 'system_health_alert'
            && str_contains((string) ($n->title ?? ''), 'Mark price never synced for MISSINGUSDT')
    );
});

it('does not fire mark_price_missing for delisted symbols', function (): void {
    $symbol = makeEligibleSymbolWithOpenPosition('DELISTED');

    // Mark the symbol as delisted — `notDelisted()` scope must filter
    // it out so we don't alert on a symbol the exchange itself stopped
    // streaming. Daemon-quiet on a delisted symbol is correct, not an
    // outage.
    $symbol->update(['is_marked_for_delisting' => true]);

    DB::table('exchange_symbol_prices')
        ->where('exchange_symbol_id', $symbol->id)
        ->update([
            'mark_price' => null,
            'mark_price_synced_at' => null,
            'created_at' => now()->subMinutes(10),
        ]);

    $this->artisan('kraite:cron-check-system-health')->assertSuccessful();

    Notification::assertNotSentTo(
        Kraite::admin(),
        AlertNotification::class,
        fn ($n) => str_contains((string) ($n->title ?? ''), 'DELISTEDUSDT')
    );
});

it('source defines the missing-grace constant and the missing branch', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(\Kraite\Core\Commands\Cronjobs\CheckSystemHealthCommand::class))->getFileName()
    );

    expect($source)->toContain('MARK_PRICE_MISSING_GRACE_MINUTES');
    expect($source)->toContain('mark_price_missing_');
    expect($source)->toContain('whereNull(\'exchange_symbol_prices.mark_price_synced_at\')');
});
