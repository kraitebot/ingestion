<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\CorrectModifiedOrderJob;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin the post-2026-05-12 contract: CorrectModifiedOrderJob::complete()
 * MUST NOT rewrite reference_price / reference_quantity.
 *
 * The original implementation snapped reference_* = price after a
 * correction "to prevent re-triggering the OrderObserver", but when
 * the correction failed to actually restore the order on the exchange
 * (gate-blocked, IP whitelist, retry exhaustion), `price` was still
 * the user's modified value at the moment `complete()` ran — and the
 * write silently corrupted the forensic anchor. The next user-side
 * modification would then drift against the corrupted reference and
 * the bot would "restore" to the wrong place.
 *
 * Contract:
 *   - complete() is a no-op for reference_*. doubleCheck()'s contract
 *     (Math::equal(price, reference_price)) is what proves the
 *     correction succeeded; if doubleCheck passed, price already
 *     equals reference_price by definition — the write is redundant.
 *   - Any future re-introduction of the reference_price write is a
 *     regression and this test will fail.
 */
uses(RefreshDatabase::class)->group('feature', 'order', 'correct-modified', 'no-corruption');

it('does NOT rewrite reference_price or reference_quantity in complete()', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'active',
        'total_limit_orders' => 4,
    ]);

    // Simulate the post-modification state: user changed the limit
    // price on the exchange from 1.35480000 to 1.30080000. WS event
    // mirrored the new price into Order::price. reference_price
    // still holds the bot's original placement intent.
    $order = Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'LIMIT',
        'status' => 'NEW',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'price' => '1.30080000',
        'quantity' => '46.20000000',
        'reference_price' => '1.35480000',
        'reference_quantity' => '46.20000000',
    ]);

    $job = new CorrectModifiedOrderJob($position->id, $order->id);

    $job->complete();

    $order->refresh();

    expect($order->reference_price)->toBe('1.35480000');
    expect($order->reference_quantity)->toBe('46.20000000');
});

it('does NOT touch reference_price even when price has been corrected back to reference', function (): void {
    $position = Position::factory()->long()->create([
        'status' => 'active',
        'total_limit_orders' => 4,
    ]);

    // Post-successful-correction state: apiModify pushed the order
    // back to the original price. price == reference_price already.
    // complete() running on this state must remain a no-op — no
    // database write is necessary, the values already align.
    $order = Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'LIMIT',
        'status' => 'NEW',
        'side' => 'BUY',
        'position_side' => 'LONG',
        'price' => '1.35480000',
        'quantity' => '46.20000000',
        'reference_price' => '1.35480000',
        'reference_quantity' => '46.20000000',
    ]);

    $beforeUpdatedAt = $order->updated_at;

    $job = new CorrectModifiedOrderJob($position->id, $order->id);

    $job->complete();

    $order->refresh();

    expect($order->reference_price)->toBe('1.35480000');
    // No-op write means updated_at must NOT have moved.
    expect($order->updated_at?->equalTo($beforeUpdatedAt))->toBeTrue();
});
