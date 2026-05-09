<?php

declare(strict_types=1);

use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\Position;

/**
 * Pin the ApiSnapshot store/fetch contract. Every exchange-state
 * fetch (account positions, mark prices, account balances) routes
 * through this morphic table; downstream gates read it via
 * `getFrom($account, 'account-positions')`. A regression in the
 * uniqueness key (`canonical`) ships as repeated INSERTs filling
 * the table indefinitely; a regression in the latest() reader
 * ships as gates reading stale snapshots and racing on closed
 * positions.
 */
it('storeFor: upserts on (relatable, canonical) — second call updates the row, not duplicates', function (): void {
    $account = Account::factory()->create();

    ApiSnapshot::storeFor($account, 'account-positions', ['BTCUSDT' => ['size' => '1']]);
    ApiSnapshot::storeFor($account, 'account-positions', ['BTCUSDT' => ['size' => '2']]);

    $rows = $account->apiSnapshots()->where('canonical', 'account-positions')->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->api_response)->toBe(['BTCUSDT' => ['size' => '2']]);
});

it('storeFor: separate canonicals create separate rows', function (): void {
    $account = Account::factory()->create();

    ApiSnapshot::storeFor($account, 'account-positions', ['x' => 1]);
    ApiSnapshot::storeFor($account, 'mark-prices', ['y' => 2]);

    expect($account->apiSnapshots()->count())->toBe(2);
});

it('getFrom: returns null when no snapshot exists', function (): void {
    $account = Account::factory()->create();

    expect(ApiSnapshot::getFrom($account, 'account-positions'))->toBeNull();
});

it('getFrom: returns the most recent snapshot (after upsert)', function (): void {
    $account = Account::factory()->create();

    ApiSnapshot::storeFor($account, 'account-positions', ['v' => 'old']);
    ApiSnapshot::storeFor($account, 'account-positions', ['v' => 'new']);

    expect(ApiSnapshot::getFrom($account, 'account-positions'))->toBe(['v' => 'new']);
});

it('getFrom: filters by canonical (no cross-canonical bleed)', function (): void {
    $account = Account::factory()->create();

    ApiSnapshot::storeFor($account, 'a', ['from' => 'a']);
    ApiSnapshot::storeFor($account, 'b', ['from' => 'b']);

    expect(ApiSnapshot::getFrom($account, 'a'))->toBe(['from' => 'a'])
        ->and(ApiSnapshot::getFrom($account, 'b'))->toBe(['from' => 'b']);
});

it('storeFor and getFrom work for any morphable model (Position too)', function (): void {
    $position = Position::factory()->long()->create();

    ApiSnapshot::storeFor($position, 'order-state', ['orders' => 4]);

    expect(ApiSnapshot::getFrom($position, 'order-state'))->toBe(['orders' => 4]);
});

it('snapshots are SCOPED to their owner — Account A snapshot does NOT leak to Account B', function (): void {
    $a = Account::factory()->create();
    $b = Account::factory()->create();

    ApiSnapshot::storeFor($a, 'account-positions', ['owner' => 'A']);

    expect(ApiSnapshot::getFrom($b, 'account-positions'))->toBeNull();
});

it('withCanonical scope filters at the query level', function (): void {
    $account = Account::factory()->create();
    ApiSnapshot::storeFor($account, 'foo', ['x' => 1]);
    ApiSnapshot::storeFor($account, 'bar', ['y' => 2]);

    $foo = $account->apiSnapshots()->withCanonical('foo')->get();

    expect($foo)->toHaveCount(1)
        ->and($foo->first()->canonical)->toBe('foo');
});
