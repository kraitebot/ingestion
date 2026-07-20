<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Jobs\Atomic\Account\SyncAccountActivityFlagsJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\User;

/*
 * The position-opening chain re-checks for user activity right after it
 * refreshes the account's exchange snapshots and BEFORE any sizing —
 * so a user who started trading manually since registration flips the
 * account to protected/available-balance mode within the same run.
 */

uses(RefreshDatabase::class);

function openingChainAccount(array $overrides = []): Account
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bitget',
        'name' => 'Bitget',
    ]);

    return Account::factory()->create(array_merge([
        'user_id' => User::factory()->create()->id,
        'api_system_id' => $apiSystem->id,
        'allow_other_positions' => false,
        'allow_other_orders' => false,
    ], $overrides));
}

function runOpeningChainFlagSync(Account $account): array
{
    $job = new SyncAccountActivityFlagsJob($account->id);

    $compute = Closure::bind(function (): array {
        return $this->compute();
    }, $job, $job::class);

    return $compute();
}

test('a foreign position in the fresh snapshot enables protection before sizing', function (): void {
    $account = openingChainAccount();

    ApiSnapshot::storeFor($account, 'account-positions', [
        'DOGEUSDT:LONG' => ['symbol' => 'DOGEUSDT', 'positionAmt' => 250.0],
    ]);
    ApiSnapshot::storeFor($account, 'account-open-orders', []);

    runOpeningChainFlagSync($account);

    expect($account->fresh()->allow_other_positions)->toBeTrue()
        ->and($account->fresh()->allow_other_orders)->toBeTrue();
});

test('a foreign limit order alone in the snapshot enables BOTH flags', function (): void {
    $account = openingChainAccount();

    ApiSnapshot::storeFor($account, 'account-positions', []);
    ApiSnapshot::storeFor($account, 'account-open-orders', [
        ['orderId' => 'user-limit-3', 'symbol' => 'BTCUSDT'],
    ]);

    runOpeningChainFlagSync($account);

    expect($account->fresh()->allow_other_positions)->toBeTrue()
        ->and($account->fresh()->allow_other_orders)->toBeTrue();
});

test('clean snapshots restore exclusive mode', function (): void {
    $account = openingChainAccount([
        'allow_other_positions' => true,
        'allow_other_orders' => true,
    ]);

    ApiSnapshot::storeFor($account, 'account-positions', []);
    ApiSnapshot::storeFor($account, 'account-open-orders', []);

    runOpeningChainFlagSync($account);

    expect($account->fresh()->allow_other_positions)->toBeFalse()
        ->and($account->fresh()->allow_other_orders)->toBeFalse();
});

test('the bot\'s own open position in the snapshot is not foreign', function (): void {
    $account = openingChainAccount();

    $exchangeSymbol = Kraite\Core\Models\ExchangeSymbol::factory()->create([
        'api_system_id' => $account->api_system_id,
        'token' => 'SOL',
        'quote' => 'USDT',
    ]);
    $own = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'SOLUSDT',
        'status' => 'active',
    ]);
    $pair = (string) $own->parsed_trading_pair;

    ApiSnapshot::storeFor($account, 'account-positions', [
        "{$pair}:LONG" => ['symbol' => $pair, 'positionAmt' => 3.0],
    ]);
    ApiSnapshot::storeFor($account, 'account-open-orders', []);

    runOpeningChainFlagSync($account);

    expect($account->fresh()->allow_other_positions)->toBeFalse()
        ->and($account->fresh()->allow_other_orders)->toBeFalse();
});

test('missing snapshots never disable protection', function (): void {
    $account = openingChainAccount([
        'allow_other_positions' => true,
        'allow_other_orders' => true,
    ]);

    runOpeningChainFlagSync($account);

    expect($account->fresh()->allow_other_positions)->toBeTrue()
        ->and($account->fresh()->allow_other_orders)->toBeTrue();
});
