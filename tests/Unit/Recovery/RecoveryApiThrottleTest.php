<?php

declare(strict_types=1);

use Kraite\Core\Contracts\ClientLevelApiThrottler;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Support\Recovery\RecoveryApiThrottle;
use Kraite\Core\Support\Throttlers\BinanceThrottler;
use Kraite\Core\Support\Throttlers\BitgetThrottler;

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

    expect(is_subclass_of(BinanceThrottler::class, ClientLevelApiThrottler::class))->toBeFalse()
        ->and($ran)->toBeTrue()
        ->and($result)->toBe('PAYLOAD');
});

it('falls straight through when the exchange has no throttler', function (): void {
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'no-such-exchange']);
    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);

    expect(RecoveryApiThrottle::call($account, fn () => 42))->toBe(42);
});

it('leaves Bitget request accounting to the HTTP client boundary', function (): void {
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);

    expect(is_subclass_of(BitgetThrottler::class, ClientLevelApiThrottler::class))->toBeTrue()
        ->and(RecoveryApiThrottle::call($account, fn () => 'PAYLOAD'))->toBe('PAYLOAD');
});
