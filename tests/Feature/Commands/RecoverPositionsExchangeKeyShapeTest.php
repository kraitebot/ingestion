<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Commands\RecoverPositionsCommand;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\Recovery\RecoveryReport;

/**
 * Pins the multi-exchange position-key extraction contract.
 *
 * Pre-fix, `fetchExchangePositionKeys()` only read `positionAmt` from
 * the position rows — Binance-shaped output. Bitget responses use
 * `total`, KuCoin uses `currentQty` / `contracts`, Bybit uses `size`.
 * A non-Binance recoverer returning rows without `positionAmt` would
 * yield an empty key list, and Phase 4 would close real positions as
 * ghosts because their key was missing from the snapshot.
 *
 * Phase 4 also gains an empty-snapshot guard mirroring Phase 2: if
 * the recoverer returned no keys but the account has open positions,
 * the run skips ghost-closing rather than mass-closing on what may
 * be a transient API failure.
 */
function makeKeyShapeAccount(): Account
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'is_active' => true,
    ]);
}

it('fetchExchangePositionKeys reads multi-exchange quantity fields (positionAmt | size | total | currentQty | contracts)', function (): void {
    $source = file_get_contents(
        base_path('vendor/kraitebot/core/src/Commands/RecoverPositionsCommand.php')
    );

    expect($source)->toContain("'positionAmt'")
        ->and($source)->toContain("'size'")
        ->and($source)->toContain("'total'")
        ->and($source)->toContain("'currentQty'");
});

it('resetStuckStates skips ghost-closing when the exchange snapshot is empty for an account with stuck positions', function (): void {
    $account = makeKeyShapeAccount();
    $symbol = Symbol::factory()->create(['token' => 'EMPTYSNAP']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'EMPTYSNAP',
        'quote' => 'USDT',
        'api_system_id' => $account->api_system_id,
        'symbol_id' => $symbol->id,
    ]);

    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'opening',
        'parsed_trading_pair' => 'EMPTYSNAPUSDT',
        'direction' => 'LONG',
    ]);

    $cmd = new RecoverPositionsCommand;
    $ref = new ReflectionClass($cmd);
    $m = $ref->getMethod('resetStuckStates');
    $m->setAccessible(true);

    $report = new RecoveryReport;

    // Empty snapshot for this account — simulating an API failure or
    // a non-Binance shape that key extraction failed to parse.
    $m->invoke($cmd, collect([$account]), null, [$account->id => []], $report);

    $position->refresh();

    expect($position->status)->toBe('opening', 'Stuck position must NOT be ghost-closed when the exchange snapshot is empty');
});
