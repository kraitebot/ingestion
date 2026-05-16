<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Account\StoreAccountBalanceJob;

it('normalizes apiQueryBalance result into account balance history values', function (): void {
    $values = StoreAccountBalanceJob::historyValuesFromBalanceResult([
        'total-wallet-balance' => '1250.75',
        'wallet-balance' => '1200.50',
        'available-balance' => '900.25',
        'cross-wallet-balance' => '1240.00',
        'cross-unrealized-pnl' => '40.25',
    ]);

    expect($values)->toBe([
        'total_wallet_balance' => 1250.75,
        'total_unrealized_profit' => 40.25,
        'total_maintenance_margin' => 0.0,
        'total_margin_balance' => 1240.0,
    ]);
});

it('falls back to wallet-balance when a legacy normalized balance omits total-wallet-balance', function (): void {
    $values = StoreAccountBalanceJob::historyValuesFromBalanceResult([
        'wallet-balance' => '777.50',
        'available-balance' => '600.00',
        'cross-unrealized-pnl' => '10.00',
    ]);

    expect($values['total_wallet_balance'])->toBe(777.5)
        ->and($values['total_margin_balance'])->toBe(777.5);
});
