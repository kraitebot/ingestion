<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\VerifyTradingPairNotOpenJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * Pin the open-pair safeguard. This job is the first step of every
 * position-dispatch lifecycle and refuses to open a duplicate symbol on
 * a symbol that already carries either:
 *
 *   1. An exchange-side open position on the requested symbol
 *   2. A pending open order on the requested symbol
 *
 * Mis-classification here is high-blast-radius — a false negative
 * dispatches a duplicate position; a false positive silently aborts a
 * legitimate slot. Both manifest only in production traffic, so the
 * decision tree is locked here against:
 *
 *   - Snapshot present + position keyed by `{pair}:{direction}` exists → blocked.
 *   - Hedge and one-way accounts both block the opposite direction.
 *   - Any open order on the symbol blocks, regardless of exchange position mode.
 *   - Both snapshots empty / missing → cleared.
 *
 * Tests build a real Position graph via factories and seed each snapshot
 * directly through `ApiSnapshot::updateOrCreate` so the job runs against
 * its actual production read path.
 */
function buildPositionForVerifyPairOpen(
    string $direction = 'LONG',
    string $tradingPair = 'BTCUSDT',
    bool $hedgeMode = true,
): Position {
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance']);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'on_hedge_mode' => $hedgeMode,
    ]);
    $symbol = Symbol::factory()->create(['cmc_id' => random_int(1_000_000, 9_999_999)]);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'symbol_id' => $symbol->id,
        'api_system_id' => $apiSystem->id,
        'token' => str_replace('USDT', '', $tradingPair),
        'quote' => 'USDT',
    ]);

    return Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => $direction,
        'status' => 'new',
        'parsed_trading_pair' => $tradingPair,
    ]);
}

function seedSnapshot(Position $position, string $canonical, array $payload): void
{
    ApiSnapshot::updateOrCreate(
        [
            'responsable_type' => Account::class,
            'responsable_id' => $position->account_id,
            'canonical' => $canonical,
        ],
        ['api_response' => $payload],
    );
}

it('clears the workflow when no snapshot data exists at all', function (): void {
    $position = buildPositionForVerifyPairOpen();

    $result = (new VerifyTradingPairNotOpenJob($position->id))->compute();

    expect($result['is_open'])->toBeFalse()
        ->and($result['reason'])->toBe('Trading pair is not open on exchange');
});

it('clears the workflow when the positions snapshot has no matching key', function (): void {
    $position = buildPositionForVerifyPairOpen(direction: 'LONG', tradingPair: 'BTCUSDT');

    seedSnapshot($position, 'account-positions', [
        'ETHUSDT:LONG' => ['symbol' => 'ETHUSDT', 'positionAmt' => 0.5],
    ]);
    seedSnapshot($position, 'account-open-orders', []);

    $result = (new VerifyTradingPairNotOpenJob($position->id))->compute();

    expect($result['is_open'])->toBeFalse();
});

it('blocks when the positions snapshot already has the same pair and direction open', function (): void {
    $position = buildPositionForVerifyPairOpen(direction: 'LONG', tradingPair: 'BTCUSDT');

    seedSnapshot($position, 'account-positions', [
        'BTCUSDT:LONG' => ['symbol' => 'BTCUSDT', 'positionAmt' => 0.1],
    ]);

    $result = (new VerifyTradingPairNotOpenJob($position->id))->compute();

    expect($result['is_open'])->toBeTrue()
        ->and($result['reason'])->toContain('BTCUSDT:LONG')
        ->and($result['reason'])->toContain('already exists');
});

it('blocks the opposite direction when the account uses hedge mode', function (): void {
    $position = buildPositionForVerifyPairOpen(direction: 'SHORT', tradingPair: 'BTCUSDT');

    seedSnapshot($position, 'account-positions', [
        'BTCUSDT:LONG' => ['symbol' => 'BTCUSDT', 'positionAmt' => 0.1],
    ]);
    seedSnapshot($position, 'account-open-orders', []);

    $result = (new VerifyTradingPairNotOpenJob($position->id))->compute();

    expect($result['is_open'])->toBeTrue()
        ->and($result['reason'])->toContain('BTCUSDT:LONG');
});

it('blocks the opposite direction when the account uses one-way mode', function (): void {
    $position = buildPositionForVerifyPairOpen(
        direction: 'SHORT',
        tradingPair: 'BTCUSDT',
        hedgeMode: false,
    );

    seedSnapshot($position, 'account-positions', [
        'BTCUSDT:LONG' => ['symbol' => 'BTCUSDT', 'positionAmt' => 0.1],
    ]);

    $result = (new VerifyTradingPairNotOpenJob($position->id))->compute();

    expect($result['is_open'])->toBeTrue()
        ->and($result['reason'])->toContain('BTCUSDT:LONG');
});

it('blocks a directional slot when a one-way BOTH position is open on the same pair', function (): void {
    $position = buildPositionForVerifyPairOpen(direction: 'LONG', tradingPair: 'BTCUSDT');

    seedSnapshot($position, 'account-positions', [
        'BTCUSDT:BOTH' => ['symbol' => 'BTCUSDT', 'positionAmt' => -0.1],
    ]);
    seedSnapshot($position, 'account-open-orders', []);

    $result = (new VerifyTradingPairNotOpenJob($position->id))->compute();

    expect($result['is_open'])->toBeTrue()
        ->and($result['reason'])->toContain('BTCUSDT:BOTH');
});

it('blocks when the open-orders snapshot has a pending order on the same pair', function (): void {
    $position = buildPositionForVerifyPairOpen(direction: 'LONG', tradingPair: 'BTCUSDT');

    seedSnapshot($position, 'account-positions', []);
    seedSnapshot($position, 'account-open-orders', [
        ['symbol' => 'BTCUSDT', 'orderId' => 'X1', 'side' => 'BUY'],
        ['symbol' => 'BTCUSDT', 'orderId' => 'X2', 'side' => 'SELL'],
    ]);

    $result = (new VerifyTradingPairNotOpenJob($position->id))->compute();

    expect($result['is_open'])->toBeTrue()
        ->and($result['open_orders_count'])->toBe(2)
        ->and($result['reason'])->toContain('Open orders exist for BTCUSDT');
});

it('blocks opposite-direction open orders in hedge mode', function (): void {
    $position = buildPositionForVerifyPairOpen(direction: 'SHORT', tradingPair: 'BTCUSDT');

    seedSnapshot($position, 'account-positions', []);
    seedSnapshot($position, 'account-open-orders', [
        ['symbol' => 'BTCUSDT', 'orderId' => 'X1', 'positionSide' => 'LONG', 'side' => 'BUY'],
    ]);

    $result = (new VerifyTradingPairNotOpenJob($position->id))->compute();

    expect($result['is_open'])->toBeTrue()
        ->and($result['open_orders_count'])->toBe(1)
        ->and($result['reason'])->toContain('Open orders exist for BTCUSDT');
});

it('blocks same-direction and ambiguous open orders in hedge mode', function (array $order): void {
    $position = buildPositionForVerifyPairOpen(direction: 'SHORT', tradingPair: 'BTCUSDT');

    seedSnapshot($position, 'account-positions', []);
    seedSnapshot($position, 'account-open-orders', [$order]);

    $result = (new VerifyTradingPairNotOpenJob($position->id))->compute();

    expect($result['is_open'])->toBeTrue();
})->with([
    'same direction' => [['symbol' => 'BTCUSDT', 'orderId' => 'X1', 'posSide' => 'short']],
    'ambiguous direction' => [['symbol' => 'BTCUSDT', 'orderId' => 'X2', 'side' => 'SELL']],
]);

it('does not block on open orders for an unrelated symbol', function (): void {
    $position = buildPositionForVerifyPairOpen(direction: 'LONG', tradingPair: 'BTCUSDT');

    seedSnapshot($position, 'account-positions', []);
    seedSnapshot($position, 'account-open-orders', [
        ['symbol' => 'SOLUSDT', 'orderId' => 'X1', 'side' => 'BUY'],
    ]);

    $result = (new VerifyTradingPairNotOpenJob($position->id))->compute();

    expect($result['is_open'])->toBeFalse();
});

it('prefers the positions-snapshot reason when both signals would block', function (): void {
    // When BOTH a position is open AND open orders exist, the position
    // signal short-circuits first. The reason string identifies the
    // position branch, not the orders branch — important so that
    // operators reading logs know which side flagged the duplicate.
    $position = buildPositionForVerifyPairOpen(direction: 'LONG', tradingPair: 'BTCUSDT');

    seedSnapshot($position, 'account-positions', [
        'BTCUSDT:LONG' => ['symbol' => 'BTCUSDT', 'positionAmt' => 0.1],
    ]);
    seedSnapshot($position, 'account-open-orders', [
        ['symbol' => 'BTCUSDT', 'orderId' => 'X1', 'side' => 'BUY'],
    ]);

    $result = (new VerifyTradingPairNotOpenJob($position->id))->compute();

    expect($result['is_open'])->toBeTrue()
        ->and($result['reason'])->toContain('already exists')
        ->and($result['reason'])->not->toContain('Open orders exist');
});
