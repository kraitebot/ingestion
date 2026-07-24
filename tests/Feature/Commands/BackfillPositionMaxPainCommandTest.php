<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * @return array{position: Position, orders: array<string, Order>}
 */
function createPositionForMaxPainBackfill(
    string $token,
    string $status = 'active',
    string $direction = 'LONG',
    bool $withActivationLog = true,
): array {
    $position = Position::factory()
        ->state(['direction' => $direction])
        ->create([
            'parsed_trading_pair' => $token,
            'status' => $status,
            'opened_at' => now()->subHour(),
            'closed_at' => $status === 'closed' ? now() : null,
            'total_limit_orders' => 1,
            'max_pain' => null,
        ]);

    $side = $direction === 'LONG' ? 'BUY' : 'SELL';
    $exitSide = $direction === 'LONG' ? 'SELL' : 'BUY';
    $prices = $direction === 'LONG'
        ? ['market' => '100', 'limit' => '90', 'profit' => '110', 'stop' => '80']
        : ['market' => '100', 'limit' => '110', 'profit' => '90', 'stop' => '130'];

    $createOrder = function (string $type, string $price, string $quantity, string $orderSide) use (
        $position,
        $direction,
        $status,
        $token,
    ): Order {
        $terminalStatus = match ($type) {
            'MARKET' => 'FILLED',
            'PROFIT-LIMIT' => $status === 'closed' ? 'FILLED' : 'NEW',
            'STOP-MARKET' => $status === 'closed' ? 'EXPIRED' : 'NEW',
            default => $status === 'closed' ? 'CANCELLED' : 'NEW',
        };

        return Order::create([
            'position_id' => $position->id,
            'type' => $type,
            'status' => $terminalStatus,
            'reference_status' => $type === 'MARKET' ? 'FILLED' : 'NEW',
            'side' => $orderSide,
            'position_side' => $direction,
            'price' => $price,
            'reference_price' => $price,
            'quantity' => $quantity,
            'reference_quantity' => $quantity,
            'exchange_order_id' => $token.'-'.Str::uuid(),
        ]);
    };

    $orders = [
        'market' => $createOrder('MARKET', $prices['market'], '2', $side),
        'limit' => $createOrder('LIMIT', $prices['limit'], $direction === 'LONG' ? '4' : '3', $side),
        'profit' => $createOrder('PROFIT-LIMIT', $prices['profit'], '2', $exitSide),
        'stop' => $createOrder('STOP-MARKET', $prices['stop'], '0', $exitSide),
    ];

    if ($withActivationLog) {
        $position->appLog(
            event: 'position_activated',
            message: "Activated {$token}",
        );
    }

    return ['position' => $position, 'orders' => $orders];
}

it('dry-runs active and closed positions without writing', function (): void {
    $active = createPositionForMaxPainBackfill('MAX-PAIN-DRY-ACTIVE');
    $closed = createPositionForMaxPainBackfill('MAX-PAIN-DRY-CLOSED', status: 'closed', direction: 'SHORT');

    expect($active['position']->max_pain)->toBeNull()
        ->and($closed['position']->max_pain)->toBeNull();

    $this->artisan('kraite:backfill-position-max-pain', ['--dry-run' => true, '--chunk' => 1])
        ->expectsOutputToContain('scanned=2 calculated=2 updated=0 skipped=0 dry_run=true')
        ->assertSuccessful();

    expect($active['position']->refresh()->max_pain)->toBeNull()
        ->and($closed['position']->refresh()->max_pain)->toBeNull();
});

it('backfills exact reference-value risk and remains idempotent', function (): void {
    $active = createPositionForMaxPainBackfill('MAX-PAIN-APPLY-ACTIVE');
    $closed = createPositionForMaxPainBackfill('MAX-PAIN-APPLY-CLOSED', status: 'closed', direction: 'SHORT');
    $existing = createPositionForMaxPainBackfill('MAX-PAIN-EXISTING');
    $existing['position']->updateSaving(['max_pain' => '12.34000000']);

    $active['orders']['limit']->updateSaving([
        'price' => '999',
        'quantity' => '999',
    ]);

    expect($active['position']->max_pain)->toBeNull()
        ->and($closed['position']->max_pain)->toBeNull()
        ->and($existing['position']->refresh()->max_pain)->toBe('12.34000000');

    $this->artisan('kraite:backfill-position-max-pain', ['--chunk' => 1])
        ->expectsOutputToContain('scanned=2 calculated=2 updated=2 skipped=0 dry_run=false')
        ->assertSuccessful();

    expect($active['position']->refresh()->max_pain)->toBe('80.00000000')
        ->and($closed['position']->refresh()->max_pain)->toBe('120.00000000')
        ->and($existing['position']->refresh()->max_pain)->toBe('12.34000000');

    $this->artisan('kraite:backfill-position-max-pain')
        ->expectsOutputToContain('scanned=0 calculated=0 updated=0 skipped=0 dry_run=false')
        ->assertSuccessful();

    expect($active['position']->refresh()->max_pain)->toBe('80.00000000')
        ->and($closed['position']->refresh()->max_pain)->toBe('120.00000000')
        ->and($existing['position']->refresh()->max_pain)->toBe('12.34000000');
});

it('leaves ambiguous and out-of-scope positions untouched', function (): void {
    $replacement = createPositionForMaxPainBackfill('MAX-PAIN-REPLACEMENT');
    $replacement['orders']['limit']->updateSaving([
        'recreated_from_order_id' => $replacement['orders']['market']->id,
    ]);

    $incomplete = createPositionForMaxPainBackfill('MAX-PAIN-INCOMPLETE');
    $incomplete['orders']['limit']->delete();

    $notActivated = createPositionForMaxPainBackfill(
        'MAX-PAIN-NOT-ACTIVATED',
        withActivationLog: false,
    );
    $failed = createPositionForMaxPainBackfill('MAX-PAIN-FAILED', status: 'failed');

    $this->artisan('kraite:backfill-position-max-pain')
        ->expectsOutputToContain('scanned=2 calculated=0 updated=0 skipped=2 dry_run=false')
        ->expectsOutputToContain('skipped_reasons=incomplete-order-graph:1,replacement-order:1')
        ->assertSuccessful();

    expect($replacement['position']->refresh()->max_pain)->toBeNull()
        ->and($incomplete['position']->refresh()->max_pain)->toBeNull()
        ->and($notActivated['position']->refresh()->max_pain)->toBeNull()
        ->and($failed['position']->refresh()->max_pain)->toBeNull();
});

it('rejects an invalid chunk size before changing data', function (): void {
    $position = createPositionForMaxPainBackfill('MAX-PAIN-BAD-CHUNK');

    $this->artisan('kraite:backfill-position-max-pain', ['--chunk' => 0])
        ->expectsOutputToContain('--chunk must be at least 1.')
        ->assertFailed();

    expect($position['position']->refresh()->max_pain)->toBeNull();
});
