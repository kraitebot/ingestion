<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Recovery\AbstractPositionRecoverer;
use Kraite\Core\Support\Recovery\RecoveryReport;

/**
 * Anti-rot contract test for disaster recovery. Exercises the shared
 * reconstruction engine (AbstractPositionRecoverer) — the schema-coupled
 * heart of the command that rebuilds the local DB when hyperion dies:
 * position create, order upsert, opening-price anchoring, TP/SL back-calc,
 * the generated is_open column, and idempotency.
 *
 * A concrete test recoverer feeds canned exchange data through the real
 * base logic against kraite_tests. This locks the contract so a future
 * core schema change (a new non-nullable column, a moved field) fails
 * loudly HERE instead of at 3am mid-disaster. The Binance API-shape
 * mapping is validated separately by the live `--dry-run`.
 */
function testRecoverer(
    Account $account,
    array $positions,
    array $openOrders,
    array $fills,
    ?RecoveryReport $report = null,
): AbstractPositionRecoverer {
    return new class($account, $report ?? new RecoveryReport, null, $positions, $openOrders, $fills) extends AbstractPositionRecoverer
    {
        public function __construct(
            Account $account,
            RecoveryReport $report,
            ?string $tokenFilter,
            private array $cannedPositions,
            private array $cannedOpenOrders,
            private array $cannedFills,
        ) {
            parent::__construct($account, $report, $tokenFilter);
        }

        protected function fetchOpenPositions(): array
        {
            return $this->cannedPositions;
        }

        protected function fetchOpenOrders(Position $position, array $exchangePosition): array
        {
            return $this->cannedOpenOrders;
        }

        protected function fetchHistoricalFills(Position $position, array $exchangePosition): array
        {
            return $this->cannedFills;
        }

        protected function toLocalOrderAttributes(Position $position, array $order, bool $isFilled): array
        {
            return [
                'position_id' => $position->id,
                'exchange_order_id' => (string) ($order['orderId'] ?? ''),
                'client_order_id' => (string) ($order['clientOrderId'] ?? Str::uuid()->toString()),
                'type' => $order['type'],
                'status' => $order['status'],
                'side' => $order['side'],
                'position_side' => $order['positionSide'] ?? $position->direction,
                'is_algo' => (bool) ($order['_isAlgo'] ?? false),
                'price' => (string) ($order['price'] ?? '0'),
                'quantity' => (string) ($order['origQty'] ?? '0'),
                'reference_price' => (string) ($order['price'] ?? '0'),
                'reference_quantity' => (string) ($order['origQty'] ?? '0'),
                'reference_status' => $order['status'],
                'opened_at' => now(),
                'filled_at' => $isFilled ? now() : null,
            ];
        }
    };
}

function cannedLongPosition(): array
{
    return [[
        'symbol' => 'APEUSDT',
        'positionAmt' => '10',
        'positionSide' => 'LONG',
        'breakEvenPrice' => '1.50',
        '_openingPrice' => '1.50',
        'leverage' => 5,
        'updateTime' => now()->getTimestampMs(),
    ]];
}

function cannedOpenOrders(): array
{
    return [
        ['orderId' => '111', 'type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'price' => '1.65', 'origQty' => '10', 'status' => 'NEW', 'positionSide' => 'LONG', '_isAlgo' => false],
        ['orderId' => '222', 'type' => 'STOP-MARKET', 'side' => 'SELL', 'price' => '1.35', 'origQty' => '10', 'status' => 'NEW', 'positionSide' => 'LONG', '_isAlgo' => true],
    ];
}

function cannedFills(): array
{
    return [
        ['orderId' => '100', 'type' => 'MARKET', 'side' => 'BUY', 'price' => '1.50', 'origQty' => '10', 'status' => 'FILLED', 'positionSide' => 'LONG', '_isAlgo' => false],
    ];
}

function seedBinanceAccountAndSymbol(): Account
{
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'token' => 'APE',
        'quote' => 'USDT',
    ]);

    return Account::factory()->create(['api_system_id' => $apiSystem->id]);
}

it('reconstructs a position with its strategy anchors', function (): void {
    $account = seedBinanceAccountAndSymbol();

    testRecoverer($account, cannedLongPosition(), cannedOpenOrders(), cannedFills())->run();

    $position = Position::query()->where('account_id', $account->id)->first();

    expect($position)->not->toBeNull();
    expect($position->status)->toBe('active');
    expect($position->direction)->toBe('LONG');
    expect((float) $position->quantity)->toBe(10.0);
    expect($position->leverage)->toBe(5);

    // Generated is_open column self-populates to 1 for an active recovered position.
    expect((int) $position->fresh()->is_open)->toBe(1);

    // opening_price anchored to the first entry fill.
    expect((float) $position->opening_price)->toBe(1.50);

    // TP/SL percentages back-calculated: |1.65-1.50|/1.50 = 10%, |1.50-1.35|/1.50 = 10%.
    expect(round((float) $position->profit_percentage, 1))->toBe(10.0);
    expect(round((float) $position->stop_market_percentage, 1))->toBe(10.0);
    expect(round((float) $position->first_profit_price, 2))->toBe(1.65);
});

it('rebuilds all three orders with correct types and the algo flag', function (): void {
    $account = seedBinanceAccountAndSymbol();

    testRecoverer($account, cannedLongPosition(), cannedOpenOrders(), cannedFills())->run();

    $orders = Position::query()->where('account_id', $account->id)->first()->orders()->get();

    expect($orders)->toHaveCount(3);

    expect($orders->firstWhere('type', 'PROFIT-LIMIT'))->not->toBeNull();

    $sl = $orders->firstWhere('type', 'STOP-MARKET');
    expect($sl)->not->toBeNull();
    expect((bool) $sl->is_algo)->toBeTrue();
    expect($sl->exchange_order_id)->toBe('222');

    $entry = $orders->firstWhere('type', 'MARKET');
    expect($entry)->not->toBeNull();
    expect($entry->status)->toBe('FILLED');
    expect((bool) $entry->is_algo)->toBeFalse();
});

it('is idempotent — a second recovery run creates nothing new', function (): void {
    $account = seedBinanceAccountAndSymbol();

    testRecoverer($account, cannedLongPosition(), cannedOpenOrders(), cannedFills())->run();
    $positionsAfterFirst = Position::count();
    $ordersAfterFirst = Order::count();

    testRecoverer($account, cannedLongPosition(), cannedOpenOrders(), cannedFills())->run();

    expect(Position::count())->toBe($positionsAfterFirst);
    expect(Order::count())->toBe($ordersAfterFirst);
});

it('rejects both exchange sides for one symbol without partially recovering either side', function (): void {
    $account = seedBinanceAccountAndSymbol();
    $report = new RecoveryReport;
    $long = cannedLongPosition()[0];
    $short = [
        ...$long,
        'positionAmt' => '-4',
        'positionSide' => 'SHORT',
    ];

    testRecoverer($account, [$long, $short], cannedOpenOrders(), cannedFills(), $report)->run();

    expect(Position::query()->where('account_id', $account->id)->count())->toBe(0)
        ->and(Order::query()->count())->toBe(0)
        ->and($report->positionsCreated)->toBe(0)
        ->and($report->positionsSkipped)->toBe(2)
        ->and($report->warnings)->toHaveCount(1)
        ->and($report->warnings[0])->toContain('simultaneous LONG and SHORT exchange positions');
});

it('does not replace an open local side with the opposite exchange side', function (): void {
    $account = seedBinanceAccountAndSymbol();
    $exchangeSymbol = ExchangeSymbol::query()->where('api_system_id', $account->api_system_id)->firstOrFail();
    $localLong = Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'APEUSDT',
        'direction' => 'LONG',
        'status' => 'active',
        'opened_at' => now()->subHour(),
    ]);
    $short = [
        ...cannedLongPosition()[0],
        'positionAmt' => '-4',
        'positionSide' => 'SHORT',
    ];
    $report = new RecoveryReport;

    testRecoverer($account, [$short], [], [], $report)->run();

    expect(Position::query()->where('account_id', $account->id)->get())->toHaveCount(1)
        ->and($localLong->fresh()->direction)->toBe('LONG')
        ->and($report->positionsCreated)->toBe(0)
        ->and($report->positionsSkipped)->toBe(1)
        ->and($report->warnings)->toHaveCount(1)
        ->and($report->warnings[0])->toContain('already has an open LONG position');
});
