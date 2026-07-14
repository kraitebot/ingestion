<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\Recovery\BinancePositionRecoverer;
use Kraite\Core\Support\Recovery\BitgetPositionRecoverer;
use Kraite\Core\Support\Recovery\BybitPositionRecoverer;
use Kraite\Core\Support\Recovery\KucoinPositionRecoverer;
use Kraite\Core\Support\Recovery\RecoveryReport;

/**
 * Pins the untested-exchange gate. Bybit + KuCoin recoverers carry an
 * `UNTESTED` docblock — no live account exists for verification today.
 * Their `isUntested()` returns true so the command skips them by
 * default. Operator must pass `--allow-untested-exchange` to bypass.
 *
 * Verified exchanges (Binance, Bitget) return false and run without
 * ceremony.
 */
function makeUntestedGateAccountFor(string $canonical): Account
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $canonical,
        'name' => ucfirst($canonical),
    ]);

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'is_active' => true,
    ]);
}

it('Binance + Bitget recoverers are NOT flagged untested', function (): void {
    $binance = new BinancePositionRecoverer(makeUntestedGateAccountFor('binance'), new RecoveryReport);
    $bitget = new BitgetPositionRecoverer(makeUntestedGateAccountFor('bitget'), new RecoveryReport);

    expect($binance->isUntested())->toBeFalse()
        ->and($bitget->isUntested())->toBeFalse();
});

it('Bybit + KuCoin recoverers ARE flagged untested', function (): void {
    $bybit = new BybitPositionRecoverer(makeUntestedGateAccountFor('bybit'), new RecoveryReport);
    $kucoin = new KucoinPositionRecoverer(makeUntestedGateAccountFor('kucoin'), new RecoveryReport);

    expect($bybit->isUntested())->toBeTrue()
        ->and($kucoin->isUntested())->toBeTrue();
});

it('command surface includes the --allow-untested-exchange option', function (): void {
    // The CLI option is declared on the command...
    $command = file_get_contents(
        base_path('vendor/kraitebot/core/src/Commands/RecoverPositionsCommand.php')
    );
    expect($command)->toContain('--allow-untested-exchange');

    // ...but the gate that acts on it moved to the per-account runner with
    // fleet fan-out: an untested recoverer is skipped unless the operator
    // opted in via the constructor-injected flag.
    $runner = file_get_contents(
        base_path('vendor/kraitebot/core/src/Support/Recovery/AccountRecoveryRunner.php')
    );
    expect($runner)->toMatch('/isUntested\(\)\s*&&\s*!\s*\$this->allowUntestedExchange/s');
});
