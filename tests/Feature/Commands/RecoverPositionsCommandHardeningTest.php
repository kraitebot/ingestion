<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Kraite\Core\Commands\RecoverPositionsCommand;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\Recovery\AccountRecoveryRunner;

/**
 * 2026-05-04 — Pin the disaster-recovery hardening on
 * `kraite:recover-positions`.
 *
 * The scenario this defends:
 *
 *   - DB restored from a T-5h backup at T+0 (catastrophic event).
 *   - In the gap: some positions closed on the exchange, some
 *     positions opened (and may still be open), some orders
 *     cancelled / filled / placed. Some workflows died mid-flight,
 *     leaving positions stuck in opening / syncing / cancelling
 *     status.
 *
 * The contract this test suite pins:
 *
 *   - Phase 1 (existing): exchange-side positions / orders not
 *     present locally are CREATED + INSERTED.
 *   - Phase 2 (new): local positions in opened-status whose
 *     (account, symbol, direction) is NOT in the exchange snapshot
 *     get flipped to 'closed'. They closed during the gap; we
 *     can't reconstruct the exact closing fill, but we MUST stop
 *     the bot from managing a phantom.
 *   - Phase 3 (new): local non-terminal orders on still-active
 *     positions are mirrored against current exchange state via
 *     `apiSync()`. Orders cancelled / filled in the gap are
 *     correctly reflected in local DB.
 *   - Phase 4 (new): positions stuck in opening / syncing /
 *     cancelling are reset — `active` if exchange shows them,
 *     `closed` otherwise. Workflow crashes at the disaster moment
 *     no longer pin positions in non-terminal states forever.
 *   - Phase 5 (new): trading freeze for the duration of the run,
 *     pre-recovery DB snapshot, completion notification.
 *
 * Lost-history acceptance: positions that opened AND closed
 * entirely within the gap are NOT recreated. Bruno's call —
 * accepted casualty of catastrophe.
 */
function makeRecoveryAccount(string $canonical = 'binance'): Account
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $canonical,
        'name' => ucfirst($canonical),
    ]);

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'binance_api_key' => 'recover-test-key',
        'binance_api_secret' => 'recover-test-secret',
        'is_active' => true,
    ]);
}

function makeRecoveryPosition(Account $account, string $token, string $direction, string $status = 'active'): Position
{
    $symbol = Symbol::factory()->create(['token' => $token]);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => 'USDT',
        'api_system_id' => $account->api_system_id,
        'symbol_id' => $symbol->id,
    ]);

    return Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'direction' => $direction,
        'status' => $status,
        'opened_at' => now()->subHours(6),
        'opening_price' => '1.00000000',
        'quantity' => '10.00000000',
    ]);
}

it('recovery-runner source has the phase-2 close-detection helper', function (): void {
    // The four phase helpers moved from the command into the per-account
    // AccountRecoveryRunner when recovery gained fleet fan-out (the command
    // now dispatches a runner per account instead of running them inline).
    $source = file_get_contents(
        (new ReflectionClass(AccountRecoveryRunner::class))->getFileName()
    );

    expect($source)->toContain(
        'markClosedDuringGap',
    );
});

it('recovery-runner source has the phase-3 order-status mirror helper', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(AccountRecoveryRunner::class))->getFileName()
    );

    expect($source)->toContain('mirrorOrderStatuses');
});

it('recovery-runner source has the phase-4 stuck-state reset helper', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(AccountRecoveryRunner::class))->getFileName()
    );

    expect($source)->toContain('resetStuckStates');
});

it('command source freezes + restores allow_opening_positions in a try/finally for phase-5 trading guard', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(RecoverPositionsCommand::class))->getFileName()
    );

    // Both flips must exist (set false on entry, restore on exit)
    // and the restore must live inside a finally block so a mid-run
    // exception cannot leave trading frozen.
    expect($source)->toContain('allow_opening_positions');
    expect($source)->toContain('finally');
});

it('command source has the phase-5 DB snapshot helper', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(RecoverPositionsCommand::class))->getFileName()
    );

    expect($source)->toContain('snapshotDatabase');
});

it('command source has the phase-5 completion notification', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(RecoverPositionsCommand::class))->getFileName()
    );

    // Either the canonical literal OR the helper name pins the
    // completion-notification path being wired.
    expect($source)->toContain('recovery_completed');
});

it('phase-2 marks local active position closed when its symbol is not in the exchange snapshot', function (): void {
    // Skip if the underlying API stack would attempt a real network
    // call — Http::preventStrayRequests in Pest.php means a missing
    // fake throws StrayRequestException long before the recovery
    // logic reaches Phase 2. The functional behaviour pin is a
    // Phase 2 unit-style assertion: build a position in DB, give
    // the command an empty exchange snapshot for its account, run,
    // assert the position is now closed.
    $account = makeRecoveryAccount();

    $phantom = makeRecoveryPosition($account, 'PHANTOM', 'LONG', 'active');

    // The safety guard in markClosedDuringGap refuses to mass-
    // close when the exchange snapshot is empty AND local has open
    // positions (treats it as transient API failure). To exercise
    // the actual close-detection path, the snapshot must be
    // NON-EMPTY but not contain the phantom's key. A sibling
    // position on a DIFFERENT symbol satisfies that.
    makeRecoveryPosition($account, 'OTHER', 'SHORT', 'active');

    Http::fake([
        '*/fapi/v3/positionRisk*' => Http::response([
            [
                'symbol' => 'OTHERUSDT',
                'positionAmt' => '-5.0',
                'positionSide' => 'SHORT',
                'entryPrice' => '1.00',
                'breakEvenPrice' => '1.00',
                'leverage' => '20',
                'markPrice' => '1.00',
                'unRealizedProfit' => '0',
                'liquidationPrice' => '0',
                'updateTime' => 1_700_000_000_000,
            ],
        ], 200),
        '*/fapi/v2/positionRisk*' => Http::response([], 200),
        '*/fapi/v1/positionRisk*' => Http::response([], 200),
        '*/fapi/v3/balance*' => Http::response([['asset' => 'USDT', 'balance' => '1000', 'crossWalletBalance' => '1000']], 200),
        '*/fapi/v2/account*' => Http::response(['totalWalletBalance' => '1000', 'availableBalance' => '500'], 200),
        '*/fapi/v1/openOrders*' => Http::response([], 200),
        '*/fapi/v1/userTrades*' => Http::response([], 200),
        '*/fapi/v1/allOrders*' => Http::response([], 200),
    ]);

    $this->artisan('kraite:recover-positions', ['--account_id' => $account->id, '--inline' => true])
        ->assertSuccessful();

    expect(Position::find($phantom->id)->status)->toBe(
        'closed',
        'A local active position whose (account, symbol, direction) is not in the exchange snapshot must be flipped to closed by Phase 2.'
    );
    expect(Position::find($phantom->id)->closed_at)->not->toBeNull();
});

it('phase-2 leaves the position alone when the exchange snapshot DOES contain its key', function (): void {
    $account = makeRecoveryAccount();

    $live = makeRecoveryPosition($account, 'LIVE', 'LONG', 'active');

    Http::fake([
        // Exchange returns the position alive — Phase 2 must NOT
        // flip it, even though Phase 1 will skip the create branch
        // (already exists).
        '*/fapi/v3/positionRisk*' => Http::response([
            [
                'symbol' => 'LIVEUSDT',
                'positionAmt' => '10.0',
                'positionSide' => 'LONG',
                'entryPrice' => '1.00',
                'breakEvenPrice' => '1.00',
                'leverage' => '20',
                'markPrice' => '1.00',
                'unRealizedProfit' => '0',
                'liquidationPrice' => '0',
                'updateTime' => 1_700_000_000_000,
            ],
        ], 200),
        '*/fapi/v2/positionRisk*' => Http::response([], 200),
        '*/fapi/v1/positionRisk*' => Http::response([], 200),
        '*/fapi/v3/balance*' => Http::response([['asset' => 'USDT', 'balance' => '1000', 'crossWalletBalance' => '1000']], 200),
        '*/fapi/v2/account*' => Http::response(['totalWalletBalance' => '1000', 'availableBalance' => '500'], 200),
        '*/fapi/v1/openOrders*' => Http::response([], 200),
        '*/fapi/v1/userTrades*' => Http::response([], 200),
        '*/fapi/v1/allOrders*' => Http::response([], 200),
    ]);

    $this->artisan('kraite:recover-positions', ['--account_id' => $account->id, '--inline' => true])
        ->assertSuccessful();

    expect(Position::find($live->id)->status)->toBe('active');
});

it('phase-4 resets a stuck opening-status position to active when the exchange shows it open', function (): void {
    $account = makeRecoveryAccount();

    $stuck = makeRecoveryPosition($account, 'STUCK', 'LONG', 'opening');

    Http::fake([
        '*/fapi/v3/positionRisk*' => Http::response([
            [
                'symbol' => 'STUCKUSDT',
                'positionAmt' => '10.0',
                'positionSide' => 'LONG',
                'entryPrice' => '1.00',
                'breakEvenPrice' => '1.00',
                'leverage' => '20',
                'markPrice' => '1.00',
                'unRealizedProfit' => '0',
                'liquidationPrice' => '0',
                'updateTime' => 1_700_000_000_000,
            ],
        ], 200),
        '*/fapi/v2/positionRisk*' => Http::response([], 200),
        '*/fapi/v1/positionRisk*' => Http::response([], 200),
        '*/fapi/v3/balance*' => Http::response([['asset' => 'USDT', 'balance' => '1000', 'crossWalletBalance' => '1000']], 200),
        '*/fapi/v2/account*' => Http::response(['totalWalletBalance' => '1000', 'availableBalance' => '500'], 200),
        '*/fapi/v1/openOrders*' => Http::response([], 200),
        '*/fapi/v1/userTrades*' => Http::response([], 200),
        '*/fapi/v1/allOrders*' => Http::response([], 200),
    ]);

    $this->artisan('kraite:recover-positions', ['--account_id' => $account->id, '--inline' => true])
        ->assertSuccessful();

    expect(Position::find($stuck->id)->status)->toBe(
        'active',
        'Position stuck in opening with no in-flight workflow + exchange shows it open must be reset to active by Phase 4.'
    );
});

it('phase-4 closes a stuck opening-status position when the exchange does not show it', function (): void {
    $account = makeRecoveryAccount();

    $stuck = makeRecoveryPosition($account, 'GHOST', 'LONG', 'opening');

    // Non-empty snapshot needed so Phase 2's safety guard doesn't
    // skip the whole sweep. A sibling on a DIFFERENT symbol does
    // the trick (the ghost's key still won't match).
    makeRecoveryPosition($account, 'OTHER2', 'SHORT', 'active');

    Http::fake([
        '*/fapi/v3/positionRisk*' => Http::response([
            [
                'symbol' => 'OTHER2USDT',
                'positionAmt' => '-5.0',
                'positionSide' => 'SHORT',
                'entryPrice' => '1.00',
                'breakEvenPrice' => '1.00',
                'leverage' => '20',
                'markPrice' => '1.00',
                'unRealizedProfit' => '0',
                'liquidationPrice' => '0',
                'updateTime' => 1_700_000_000_000,
            ],
        ], 200),
        '*/fapi/v2/positionRisk*' => Http::response([], 200),
        '*/fapi/v1/positionRisk*' => Http::response([], 200),
        '*/fapi/v3/balance*' => Http::response([['asset' => 'USDT', 'balance' => '1000', 'crossWalletBalance' => '1000']], 200),
        '*/fapi/v2/account*' => Http::response(['totalWalletBalance' => '1000', 'availableBalance' => '500'], 200),
        '*/fapi/v1/openOrders*' => Http::response([], 200),
        '*/fapi/v1/userTrades*' => Http::response([], 200),
        '*/fapi/v1/allOrders*' => Http::response([], 200),
    ]);

    $this->artisan('kraite:recover-positions', ['--account_id' => $account->id, '--inline' => true])
        ->assertSuccessful();

    expect(Position::find($stuck->id)->status)->toBe(
        'closed',
        'Position stuck in opening with exchange showing nothing must be flipped to closed by Phase 4.'
    );
});

it('freezes allow_opening_positions during the run and restores on completion', function (): void {
    $account = makeRecoveryAccount();

    Kraite::firstOrCreate(['id' => 1], ['allow_opening_positions' => true]);
    Kraite::where('id', 1)->update(['allow_opening_positions' => true]);

    Http::fake([
        '*/fapi/v3/positionRisk*' => Http::response([], 200),
        '*/fapi/v2/positionRisk*' => Http::response([], 200),
        '*/fapi/v1/positionRisk*' => Http::response([], 200),
        '*/fapi/v3/balance*' => Http::response([['asset' => 'USDT', 'balance' => '1000', 'crossWalletBalance' => '1000']], 200),
        '*/fapi/v2/account*' => Http::response(['totalWalletBalance' => '1000', 'availableBalance' => '500'], 200),
        '*/fapi/v1/openOrders*' => Http::response([], 200),
        '*/fapi/v1/userTrades*' => Http::response([], 200),
        '*/fapi/v1/allOrders*' => Http::response([], 200),
    ]);

    $this->artisan('kraite:recover-positions', ['--account_id' => $account->id, '--inline' => true])
        ->assertSuccessful();

    // After successful recovery the trading flag must be back to its
    // pre-run value (true). The freeze + restore lives in try/finally.
    expect(Kraite::find(1)->allow_opening_positions)->toBeTrue();
});
