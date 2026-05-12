<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Kraite\Core\Models\ApiDataStream;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * The kraite:backfill-original-prices command stamps the immutable
 * forensic anchors (`original_price`, `original_quantity`) onto
 * pre-migration order rows that were inserted before the columns
 * existed. Source-of-truth priority:
 *
 *   1. earliest api_data_stream NEW event for the order's
 *      exchange_order_id — the WS daemon recorded the exchange's
 *      first echo of the placed values, the closest-to-source signal
 *      we still hold.
 *   2. the order's own reference_price / reference_quantity — best
 *      guess for orders whose NEW event never reached our DB (WS
 *      daemon down at placement, legacy rows pre-WS, etc.).
 *   3. the order's own price / quantity — last-resort fallback.
 *
 * Idempotent: rows that already have non-null originals are skipped.
 */
uses(RefreshDatabase::class)->group('feature', 'command', 'backfill', 'original-price');

beforeEach(function () {
    $this->apiSystem = ApiSystem::firstWhere('canonical', 'binance')
        ?? ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
});

function makeOrderForBackfill(Position $position, array $attrs = []): Order
{
    $defaults = [
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'LIMIT',
        'status' => 'NEW',
        'side' => 'BUY',
        'position_side' => $position->direction,
        'price' => '1.30080000',
        'quantity' => '46.20000000',
        'reference_price' => '1.35480000',
        'reference_quantity' => '46.20000000',
        'exchange_order_id' => '148843699877',
    ];

    $attrs = array_merge($defaults, $attrs);

    // Bypass the immutability observer for fixture setup. Tests
    // simulate the pre-migration state: rows that lack original_*
    // columns at the moment the migration ran.
    $order = Order::create($attrs);

    Illuminate\Support\Facades\DB::table('orders')
        ->where('id', $order->id)
        ->update(['original_price' => null, 'original_quantity' => null]);

    return $order->fresh();
}

it('backfills original_price from earliest api_data_stream NEW event for the order', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $order = makeOrderForBackfill($position);

    ApiDataStream::create([
        'account_id' => $position->account_id,
        'api_system_id' => $this->apiSystem->id,
        'raw_event_type' => 'ORDER_TRADE_UPDATE',
        'event_type' => 'order_update',
        'exchange_order_id' => '148843699877',
        'symbol' => 'XRPUSDT',
        'status' => 'NEW',
        'normalized_status' => 'NEW',
        'price' => '1.35480000',
        'original_quantity' => '46.20000000',
        'event_time' => now(),
        'received_at' => now(),
        'raw_payload' => ['o' => ['p' => '1.3548']],
        'idempotency_key' => 'test-key-1',
    ]);

    Artisan::call('kraite:backfill-original-prices');

    $order->refresh();

    expect($order->original_price)->toBe('1.3548');
    expect($order->original_quantity)->toBe('46.2');
});

it('falls back to reference_price when no api_data_stream NEW event exists', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $order = makeOrderForBackfill($position, [
        'exchange_order_id' => '999999999',
        'reference_price' => '1.35480000',
        'reference_quantity' => '46.20000000',
    ]);

    Artisan::call('kraite:backfill-original-prices');

    $order->refresh();

    expect($order->original_price)->toBe('1.3548');
    expect($order->original_quantity)->toBe('46.2');
});

it('falls back to price when both api_data_stream and reference are absent', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $order = makeOrderForBackfill($position, [
        'exchange_order_id' => null,
        'reference_price' => null,
        'reference_quantity' => null,
        'price' => '1.30080000',
        'quantity' => '46.20000000',
    ]);

    Artisan::call('kraite:backfill-original-prices');

    $order->refresh();

    expect($order->original_price)->toBe('1.3008');
    expect($order->original_quantity)->toBe('46.2');
});

it('is idempotent — does NOT overwrite rows that already have originals', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    // Create an order normally — observer stamps originals from price.
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
        'exchange_order_id' => '148843699877',
    ]);

    // Drop in a misleading api_data_stream record — the command must
    // ignore it since this row already has originals.
    ApiDataStream::create([
        'account_id' => $position->account_id,
        'api_system_id' => $this->apiSystem->id,
        'raw_event_type' => 'ORDER_TRADE_UPDATE',
        'event_type' => 'order_update',
        'exchange_order_id' => '148843699877',
        'symbol' => 'XRPUSDT',
        'status' => 'NEW',
        'normalized_status' => 'NEW',
        'price' => '999.00000000',
        'original_quantity' => '999.00000000',
        'event_time' => now(),
        'received_at' => now(),
        'raw_payload' => ['o' => ['p' => '999.0']],
        'idempotency_key' => 'test-key-2',
    ]);

    Artisan::call('kraite:backfill-original-prices');

    $order->refresh();

    expect($order->original_price)->toBe('1.3548');
});

it('uses the EARLIEST api_data_stream NEW event when multiple exist', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);

    $order = makeOrderForBackfill($position);

    // Drift event — same exchange_order_id, status NEW (an AMENDMENT
    // arrives with native NEW too), but later in time. The backfill
    // must prefer the earliest row because that's the original
    // placement echo, not a post-modification mirror.
    ApiDataStream::create([
        'account_id' => $position->account_id,
        'api_system_id' => $this->apiSystem->id,
        'raw_event_type' => 'ORDER_TRADE_UPDATE',
        'event_type' => 'order_update',
        'exchange_order_id' => '148843699877',
        'symbol' => 'XRPUSDT',
        'status' => 'NEW',
        'normalized_status' => 'NEW',
        'price' => '1.30080000',
        'original_quantity' => '46.20000000',
        'event_time' => now()->subMinute(),
        'received_at' => now()->subMinute(),
        'raw_payload' => ['o' => ['p' => '1.3008']],
        'idempotency_key' => 'test-key-late',
    ]);

    ApiDataStream::create([
        'account_id' => $position->account_id,
        'api_system_id' => $this->apiSystem->id,
        'raw_event_type' => 'ORDER_TRADE_UPDATE',
        'event_type' => 'order_update',
        'exchange_order_id' => '148843699877',
        'symbol' => 'XRPUSDT',
        'status' => 'NEW',
        'normalized_status' => 'NEW',
        'price' => '1.35480000',
        'original_quantity' => '46.20000000',
        'event_time' => now()->subHours(2),
        'received_at' => now()->subHours(2),
        'raw_payload' => ['o' => ['p' => '1.3548']],
        'idempotency_key' => 'test-key-early',
    ]);

    Artisan::call('kraite:backfill-original-prices');

    $order->refresh();

    expect($order->original_price)->toBe('1.3548');
});
