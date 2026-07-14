<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\CancelPositionOpenOrdersJob;
use Kraite\Core\Jobs\Atomic\UserDataStream\ProcessUserDataEventJob;
use Kraite\Core\Jobs\Lifecycles\Position\PreparePositionReplacementJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\ApiDataMappers\Binance\BinanceApiDataMapper;
use StepDispatcher\Models\Step;
use StepDispatcher\Models\StepsDispatcher;
use StepDispatcher\Support\Steps;

beforeEach(function (): void {
    StepsDispatcher::updateOrCreate(['group' => 'flat-position-test'], ['can_dispatch' => true]);
});

/**
 * @return array{account: Account, apiSystem: ApiSystem, position: Position}
 */
function buildFlatPositionUpdateFixture(
    string $token,
    string $direction = 'LONG',
    string $status = 'active',
): array {
    $apiSystem = ApiSystem::query()->firstOrCreate(
        ['canonical' => 'binance'],
        ['name' => 'Binance', 'is_exchange' => true],
    );
    $symbol = Symbol::factory()->create(['token' => $token]);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'token' => $token,
        'quote' => 'USDT',
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'binance_api_key' => 'flat-test-key',
        'binance_api_secret' => 'flat-test-secret',
    ]);
    $position = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'direction' => $direction,
        'status' => $status,
    ]);

    return compact('account', 'apiSystem', 'position');
}

function attachFlatPositionOrder(Position $position, string $type, string $exchangeOrderId): Order
{
    return Order::withoutEvents(fn (): Order => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => $exchangeOrderId,
        'type' => $type,
        'side' => $position->direction === 'LONG' ? 'BUY' : 'SELL',
        'position_side' => $position->direction,
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '1.00000000',
        'quantity' => '10.00000000',
        'reference_price' => '1.00000000',
        'reference_quantity' => '10.00000000',
        'is_algo' => false,
    ]));
}

/**
 * @return array<string, mixed>
 */
function binanceAccountPositionUpdate(
    string $symbol,
    string $positionSide,
    string $positionAmount,
    int $eventTime = 1_780_000_000_000,
): array {
    return [
        'e' => 'ACCOUNT_UPDATE',
        'E' => $eventTime,
        'T' => $eventTime,
        'a' => [
            'm' => 'ORDER',
            'B' => [],
            'P' => [[
                's' => $symbol,
                'pa' => $positionAmount,
                'ep' => '1.00000000',
                'cr' => '0',
                'up' => '0',
                'mt' => 'isolated',
                'iw' => '0',
                'ps' => $positionSide,
            ]],
        ],
    ];
}

function processFlatPositionUpdate(array $fixture, array $payload): array
{
    return Steps::usingPrefix('trading', fn (): array => (new ProcessUserDataEventJob(
        accountId: $fixture['account']->id,
        apiSystemId: $fixture['apiSystem']->id,
        apiSystemCanonical: 'binance',
        payload: $payload,
    ))->compute());
}

/**
 * @return Collection<int, Step>
 */
function immediateOpeningCancellationSteps(Position $position): Collection
{
    return Steps::usingPrefix('trading', fn () => Step::query()
        ->forRelatable($position)
        ->forClasses(CancelPositionOpenOrdersJob::class)
        ->get()
        ->filter(fn (Step $step): bool => ($step->arguments['openingOrdersOnly'] ?? false) === true)
        ->values());
}

it('normalizes Binance account position updates for hedge and one-way modes', function (
    string $positionSide,
    string $positionAmount,
): void {
    $event = (new BinanceApiDataMapper)->resolveUserDataStreamEvent(
        binanceAccountPositionUpdate('FILUSDT', $positionSide, $positionAmount),
    );

    expect($event->eventType)->toBe('account_update')
        ->and($event->positionUpdates)->toBe([[
            'symbol' => 'FILUSDT',
            'position_side' => $positionSide,
            'quantity' => $positionAmount,
        ]]);
})->with([
    'hedge LONG' => ['LONG', '0'],
    'hedge SHORT' => ['SHORT', '0.00000000'],
    'one-way BOTH' => ['BOTH', '-2.50000000'],
]);

it('dispatches an independent high-priority opening-order cancellation when the exchange reports flat', function (): void {
    $fixture = buildFlatPositionUpdateFixture('FLATLONG');
    attachFlatPositionOrder($fixture['position'], 'LIMIT', 'FLAT-LIMIT-1');

    $result = processFlatPositionUpdate(
        $fixture,
        binanceAccountPositionUpdate('FLATLONGUSDT', 'LONG', '0'),
    );
    $steps = immediateOpeningCancellationSteps($fixture['position']);

    expect($result['opening_orders_cancel_dispatched'])->toBeTrue()
        ->and($steps)->toHaveCount(1)
        ->and($steps->first()->priority)->toBe('high')
        ->and($steps->first()->queue)->toBe('priority')
        ->and($steps->first()->child_block_uuid)->toBeNull()
        ->and($steps->first()->isOrphan())->toBeTrue();
});

it('does not dispatch opening-order cancellation for a non-flat position update', function (): void {
    $fixture = buildFlatPositionUpdateFixture('NONFLAT');
    attachFlatPositionOrder($fixture['position'], 'LIMIT', 'NONFLAT-LIMIT-1');

    $result = processFlatPositionUpdate(
        $fixture,
        binanceAccountPositionUpdate('NONFLATUSDT', 'LONG', '1.25'),
    );

    expect($result['opening_orders_cancel_dispatched'])->toBeFalse()
        ->and(immediateOpeningCancellationSteps($fixture['position']))->toHaveCount(0);
});

it('matches hedge updates to the bot position direction', function (): void {
    $fixture = buildFlatPositionUpdateFixture('HEDGESIDE', direction: 'LONG');
    attachFlatPositionOrder($fixture['position'], 'LIMIT', 'HEDGE-LIMIT-1');

    $wrongSideResult = processFlatPositionUpdate(
        $fixture,
        binanceAccountPositionUpdate('HEDGESIDEUSDT', 'SHORT', '0', eventTime: 1_780_000_000_001),
    );
    $matchingSideResult = processFlatPositionUpdate(
        $fixture,
        binanceAccountPositionUpdate('HEDGESIDEUSDT', 'LONG', '0', eventTime: 1_780_000_000_002),
    );

    expect($wrongSideResult['opening_orders_cancel_dispatched'])->toBeFalse()
        ->and($matchingSideResult['opening_orders_cancel_dispatched'])->toBeTrue()
        ->and(immediateOpeningCancellationSteps($fixture['position']))->toHaveCount(1);
});

it('matches one-way BOTH updates to the sole bot position on the symbol', function (): void {
    $fixture = buildFlatPositionUpdateFixture('ONEWAY', direction: 'SHORT');
    attachFlatPositionOrder($fixture['position'], 'LIMIT', 'ONEWAY-LIMIT-1');

    $result = processFlatPositionUpdate(
        $fixture,
        binanceAccountPositionUpdate('ONEWAYUSDT', 'BOTH', '0'),
    );

    expect($result['opening_orders_cancel_dispatched'])->toBeTrue()
        ->and(immediateOpeningCancellationSteps($fixture['position']))->toHaveCount(1);
});

it('does not dispatch for an unrelated symbol or a position without live opening orders', function (): void {
    $unrelatedFixture = buildFlatPositionUpdateFixture('BOTPAIR');
    attachFlatPositionOrder($unrelatedFixture['position'], 'LIMIT', 'BOTPAIR-LIMIT-1');

    $unrelatedResult = processFlatPositionUpdate(
        $unrelatedFixture,
        binanceAccountPositionUpdate('OTHERPAIRUSDT', 'LONG', '0'),
    );

    $noLimitsFixture = buildFlatPositionUpdateFixture('NOLIMITS');
    attachFlatPositionOrder($noLimitsFixture['position'], 'PROFIT-LIMIT', 'NOLIMITS-TP-1');
    $noLimitsResult = processFlatPositionUpdate(
        $noLimitsFixture,
        binanceAccountPositionUpdate('NOLIMITSUSDT', 'LONG', '0'),
    );

    expect($unrelatedResult['opening_orders_cancel_dispatched'])->toBeFalse()
        ->and($noLimitsResult['opening_orders_cancel_dispatched'])->toBeFalse()
        ->and(immediateOpeningCancellationSteps($unrelatedFixture['position']))->toHaveCount(0)
        ->and(immediateOpeningCancellationSteps($noLimitsFixture['position']))->toHaveCount(0);
});

it('deduplicates replayed flat frames into one immediate cancellation step', function (): void {
    $fixture = buildFlatPositionUpdateFixture('FLATDEDUPE');
    attachFlatPositionOrder($fixture['position'], 'LIMIT', 'DEDUPE-LIMIT-1');
    $payload = binanceAccountPositionUpdate('FLATDEDUPEUSDT', 'LONG', '0');

    $first = processFlatPositionUpdate($fixture, $payload);
    $second = processFlatPositionUpdate($fixture, $payload);

    expect($first['recorded'])->toBeTrue()
        ->and($first['opening_orders_cancel_dispatched'])->toBeTrue()
        ->and($second['recorded'])->toBeFalse()
        ->and($second['opening_orders_cancel_dispatched'])->toBeFalse()
        ->and(immediateOpeningCancellationSteps($fixture['position']))->toHaveCount(1);
});

it('dispatches immediate cancellation even when a replacement workflow is already live', function (): void {
    $fixture = buildFlatPositionUpdateFixture('FILRACE');
    attachFlatPositionOrder($fixture['position'], 'LIMIT', 'FILRACE-LIMIT-1');
    $takeProfit = attachFlatPositionOrder($fixture['position'], 'PROFIT-LIMIT', 'FILRACE-TP-1');

    Steps::usingPrefix('trading', function () use ($takeProfit): void {
        $takeProfit->update(['status' => 'EXPIRED']);
    });

    $replacementCountBefore = Steps::usingPrefix('trading', fn (): int => Step::query()
        ->forRelatable($fixture['position'])
        ->forClasses(PreparePositionReplacementJob::class)
        ->count());

    $result = processFlatPositionUpdate(
        $fixture,
        binanceAccountPositionUpdate('FILRACEUSDT', 'LONG', '0'),
    );

    expect($replacementCountBefore)->toBe(1)
        ->and($result['opening_orders_cancel_dispatched'])->toBeTrue()
        ->and(immediateOpeningCancellationSteps($fixture['position']))->toHaveCount(1);
});
