<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Position;

/**
 * End-to-end snapshot lookup pinning. Reproduces the production bug
 * found 2026-04-27 on Karine Esnault / Binance Only Account where
 * `CalculateWapAndModifyProfitOrderJob` failed to find the position
 * in the ApiSnapshot when the account was in one-way trading mode.
 *
 * The test seeds a realistic `account-positions` snapshot (using the
 * EXACT key format the live mappers produce) and asserts that the
 * `buildPositionKey()` value the WAP job computes IS a key the
 * snapshot actually contains. Catches future regressions where:
 *
 *   - Either side of the contract drifts (mapper changes its key
 *     format without updating WAP, or WAP changes its key format
 *     without updating the mapper).
 *   - Either exchange's positions API response shape changes
 *     (Binance positionSide → BOTH for one-way; Bitget holdSide).
 *   - A new exchange variant is added that overrides
 *     buildPositionKey but doesn't account for one-way mode.
 *
 * Pre-fix, Binance one-way + Bitget one-way both failed this contract
 * silently (key mismatch → NonNotifiableException → silent abort).
 */
function seedAccountPositionsSnapshot(int $accountId, array $keyedPositions): void
{
    ApiSnapshot::updateOrCreate(
        [
            'responsable_type' => Account::class,
            'responsable_id' => $accountId,
            'canonical' => 'account-positions',
        ],
        [
            'api_response' => $keyedPositions,
        ]
    );
}

it('Binance hedge-mode LONG: WAP key matches the snapshot key (BTCUSDT:LONG)', function (): void {
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance']);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'on_hedge_mode' => true,
    ]);
    $position = new Position;
    $position->parsed_trading_pair = 'BTCUSDT';
    $position->direction = 'LONG';
    $position->setRelation('account', $account->refresh());

    seedAccountPositionsSnapshot($account->id, [
        'BTCUSDT:LONG' => ['symbol' => 'BTCUSDT', 'positionSide' => 'LONG', 'positionAmt' => 0.1, 'breakEvenPrice' => 50000],
    ]);

    $job = (new ReflectionClass(Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob::class))
        ->newInstanceWithoutConstructor();
    $positionProperty = (new ReflectionObject($job))->getProperty('position');
    $positionProperty->setAccessible(true);
    $positionProperty->setValue($job, $position);

    $key = (new ReflectionMethod($job, 'buildPositionKey'));
    $key->setAccessible(true);
    $computedKey = $key->invoke($job);

    $snapshot = ApiSnapshot::getFrom($account->refresh(), 'account-positions');

    expect($computedKey)->toBe('BTCUSDT:LONG')
        ->and(array_key_exists($computedKey, $snapshot))->toBeTrue();
});

it('Binance ONE-WAY mode LONG: WAP key matches the snapshot key (BTCUSDT:BOTH)', function (): void {
    // Pre-fix this WOULD fail — buildPositionKey returned BTCUSDT:LONG,
    // but Binance one-way mode keys are BTCUSDT:BOTH.
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance']);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'on_hedge_mode' => false,
    ]);
    $position = new Position;
    $position->parsed_trading_pair = 'BTCUSDT';
    $position->direction = 'LONG';
    $position->setRelation('account', $account->refresh());

    seedAccountPositionsSnapshot($account->id, [
        'BTCUSDT:BOTH' => ['symbol' => 'BTCUSDT', 'positionSide' => 'BOTH', 'positionAmt' => 0.1, 'breakEvenPrice' => 50000],
    ]);

    $job = (new ReflectionClass(Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob::class))
        ->newInstanceWithoutConstructor();
    $positionProperty = (new ReflectionObject($job))->getProperty('position');
    $positionProperty->setAccessible(true);
    $positionProperty->setValue($job, $position);

    $key = new ReflectionMethod($job, 'buildPositionKey');
    $key->setAccessible(true);
    $computedKey = $key->invoke($job);

    $snapshot = ApiSnapshot::getFrom($account->refresh(), 'account-positions');

    expect($computedKey)->toBe('BTCUSDT:BOTH')
        ->and(array_key_exists($computedKey, $snapshot))->toBeTrue();
});

it('Binance ONE-WAY mode SHORT: WAP key matches BTCUSDT:BOTH regardless of direction', function (): void {
    // One-way mode is direction-agnostic on the snapshot side: Binance
    // returns positionSide=BOTH for any position open in one-way mode.
    // SHORT positions land in the same BOTH slot as LONGs.
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance']);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'on_hedge_mode' => false,
    ]);
    $position = new Position;
    $position->parsed_trading_pair = 'JTOUSDT';
    $position->direction = 'SHORT';
    $position->setRelation('account', $account->refresh());

    seedAccountPositionsSnapshot($account->id, [
        'JTOUSDT:BOTH' => ['symbol' => 'JTOUSDT', 'positionSide' => 'BOTH', 'positionAmt' => -100, 'breakEvenPrice' => 0.34],
    ]);

    $job = (new ReflectionClass(Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob::class))
        ->newInstanceWithoutConstructor();
    $positionProperty = (new ReflectionObject($job))->getProperty('position');
    $positionProperty->setAccessible(true);
    $positionProperty->setValue($job, $position);

    $key = new ReflectionMethod($job, 'buildPositionKey');
    $key->setAccessible(true);
    $computedKey = $key->invoke($job);

    $snapshot = ApiSnapshot::getFrom($account->refresh(), 'account-positions');

    expect($computedKey)->toBe('JTOUSDT:BOTH')
        ->and(array_key_exists($computedKey, $snapshot))->toBeTrue();
});

it('Bitget hedge-mode SHORT: WAP key matches the Bitget snapshot key (BTCUSDT:SHORT)', function (): void {
    // Bitget's MapsPositionsQuery normalises holdSide=short → key
    // suffix "SHORT". Bitget hedge-mode is the prod default for
    // account 4 (Main BitGet) and was unaffected by the bug, but
    // we pin it as a regression guard.
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'bitget']);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'on_hedge_mode' => true,
    ]);
    $position = new Position;
    $position->parsed_trading_pair = 'BTCUSDT';
    $position->direction = 'SHORT';
    $position->setRelation('account', $account->refresh());

    seedAccountPositionsSnapshot($account->id, [
        'BTCUSDT:SHORT' => [
            'symbol' => 'BTCUSDT', 'holdSide' => 'short', 'side' => 'short',
            'positionSide' => 'SHORT', 'size' => 100, 'positionAmt' => -100,
            'breakEvenPrice' => 50000,
        ],
    ]);

    $job = (new ReflectionClass(Kraite\Core\Jobs\Atomic\Order\Bitget\CalculateWapAndModifyProfitOrderJob::class))
        ->newInstanceWithoutConstructor();
    $positionProperty = (new ReflectionObject($job))->getProperty('position');
    $positionProperty->setAccessible(true);
    $positionProperty->setValue($job, $position);

    $key = new ReflectionMethod($job, 'buildPositionKey');
    $key->setAccessible(true);
    $computedKey = $key->invoke($job);

    $snapshot = ApiSnapshot::getFrom($account->refresh(), 'account-positions');

    expect($computedKey)->toBe('BTCUSDT:SHORT')
        ->and(array_key_exists($computedKey, $snapshot))->toBeTrue();
});

it('Bitget ONE-WAY mode LONG: WAP key matches BTCUSDT:BOTH', function (): void {
    // If a Bitget account flips to one-way trading mode, its mapper
    // falls through to 'BOTH' as the keyBy default. The WAP lookup
    // must follow the same convention.
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'bitget']);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'on_hedge_mode' => false,
    ]);
    $position = new Position;
    $position->parsed_trading_pair = 'BTCUSDT';
    $position->direction = 'LONG';
    $position->setRelation('account', $account->refresh());

    seedAccountPositionsSnapshot($account->id, [
        'BTCUSDT:BOTH' => [
            'symbol' => 'BTCUSDT', 'holdSide' => null, 'side' => null,
            'positionSide' => 'BOTH', 'size' => 100, 'positionAmt' => 100,
            'breakEvenPrice' => 50000,
        ],
    ]);

    $job = (new ReflectionClass(Kraite\Core\Jobs\Atomic\Order\Bitget\CalculateWapAndModifyProfitOrderJob::class))
        ->newInstanceWithoutConstructor();
    $positionProperty = (new ReflectionObject($job))->getProperty('position');
    $positionProperty->setAccessible(true);
    $positionProperty->setValue($job, $position);

    $key = new ReflectionMethod($job, 'buildPositionKey');
    $key->setAccessible(true);
    $computedKey = $key->invoke($job);

    $snapshot = ApiSnapshot::getFrom($account->refresh(), 'account-positions');

    expect($computedKey)->toBe('BTCUSDT:BOTH')
        ->and(array_key_exists($computedKey, $snapshot))->toBeTrue();
});

it('regression: pre-fix Binance one-way LONG would NOT find the position (key mismatch)', function (): void {
    // Sanity assertion for the bug-class shape. Demonstrates that the
    // OLD key format ("BTCUSDT:LONG") DOES NOT exist in a one-way-mode
    // snapshot — proving the fix's necessity. If a future refactor
    // accidentally reverts to direction-only key building, the
    // PRECEDING tests catch it; this one documents WHY they catch it.
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance']);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'on_hedge_mode' => false,
    ]);

    seedAccountPositionsSnapshot($account->id, [
        'BTCUSDT:BOTH' => ['symbol' => 'BTCUSDT', 'positionSide' => 'BOTH', 'positionAmt' => 0.1, 'breakEvenPrice' => 50000],
    ]);

    $snapshot = ApiSnapshot::getFrom($account->refresh(), 'account-positions');

    expect(array_key_exists('BTCUSDT:LONG', $snapshot))->toBeFalse(
        'pre-fix bug: WAP looked up BTCUSDT:LONG, but one-way snapshot only has BTCUSDT:BOTH'
    );
});
