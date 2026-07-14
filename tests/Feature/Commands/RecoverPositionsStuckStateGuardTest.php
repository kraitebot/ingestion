<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\Recovery\AccountRecoveryRunner;
use Kraite\Core\Support\Recovery\RecoveryReport;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Pending;
use StepDispatcher\Support\Steps;

/**
 * Pins the in-flight-step guard for `resetStuckStates()`.
 *
 * The Phase 4 docblock claims the precondition is "no in-flight workflow
 * can be running". Pre-fix, the method's body had no actual check —
 * a `cancelling` position with a queued PrepareCancelPositionJob step
 * would get flipped to `active` while the queued step waited to be
 * picked up. Re-activating the dispatcher in the finally would then
 * dispatch the queued step against state recovery just rewrote.
 */
function makeStuckStateGuardEnv(string $token): array
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => $token]);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'is_active' => true,
    ]);

    return [$account, $exchangeSymbol];
}

function invokeResetStuckStates(Account $account, ?string $tokenFilter, array $exchangeKeys, RecoveryReport $report): void
{
    // resetStuckStates moved to the per-account AccountRecoveryRunner when
    // recovery gained fleet fan-out. Its signature is now per-account: a
    // flat list of live exchange keys for this one account, not the old
    // [account_id => keys] map.
    $runner = new AccountRecoveryRunner($account, $report, $tokenFilter);
    $m = new ReflectionMethod($runner, 'resetStuckStates');
    $m->invoke($runner, $exchangeKeys);
}

it('resetStuckStates skips a stuck position when an in-flight trading step references it', function (): void {
    [$account, $exchangeSymbol] = makeStuckStateGuardEnv('STKGRD');

    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'opening',
        'parsed_trading_pair' => 'STKGRDUSDT',
        'direction' => 'LONG',
    ]);

    Steps::usingPrefix('trading', fn () => Step::query()->insertGetId([
        'class' => 'SomePendingTradingStep',
        'queue' => 'positions',
        'state' => Pending::class,
        'arguments' => json_encode(['positionId' => $position->id]),
        'block_uuid' => (string) Str::uuid(),
        'workflow_id' => null,
        'index' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]));

    // Non-empty keys containing this position's key: the empty-snapshot
    // guard is bypassed, so the ONLY reason it stays 'opening' is the
    // in-flight-step guard — which is exactly what this test pins.
    $report = new RecoveryReport;
    invokeResetStuckStates($account, null, ['STKGRDUSDT:LONG'], $report);

    $position->refresh();

    expect($position->status)->toBe('opening');
});

it('resetStuckStates DOES reset a stuck position with no in-flight steps', function (): void {
    [$account, $exchangeSymbol] = makeStuckStateGuardEnv('STKOK');

    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'opening',
        'parsed_trading_pair' => 'STKOKUSDT',
        'direction' => 'LONG',
    ]);

    $report = new RecoveryReport;
    invokeResetStuckStates($account, null, ['STKOKUSDT:LONG'], $report);

    $position->refresh();

    expect($position->status)->toBe('active');
});
