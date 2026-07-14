<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\Recovery\RecoveryApiThrottle;

/**
 * RecoveryApiThrottle wraps a recovery-flow exchange call in the per-IP
 * rate-limit gate. It must always return the wrapped call's result, and
 * fall straight through for an exchange that has no throttler registered.
 */
it('returns the wrapped call result while gating through the exchange throttler', function (): void {
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);

    $ran = false;
    $result = RecoveryApiThrottle::call($account, function () use (&$ran) {
        $ran = true;

        return 'PAYLOAD';
    });

    expect($ran)->toBeTrue();
    expect($result)->toBe('PAYLOAD');
});

it('falls straight through when the exchange has no throttler', function (): void {
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'no-such-exchange']);
    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);

    expect(RecoveryApiThrottle::call($account, fn () => 42))->toBe(42);
});
