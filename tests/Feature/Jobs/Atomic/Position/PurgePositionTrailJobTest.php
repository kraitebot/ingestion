<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\PurgePositionTrailJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

/**
 * Pin the breadcrumb janitor.
 *
 * Two responsibilities verified end-to-end:
 *   1. PositionObserver dispatches PurgePositionTrailJob ONLY when a
 *      position transitions to `closed`. `cancelled` and `failed`
 *      exits keep their full diagnostic trail.
 *   2. The job itself wipes the polymorphic breadcrumbs on every
 *      target table while leaving the position row + its orders
 *      intact.
 */
function buildClosablePosition(string $token = 'PURGE'): Position
{
    $token .= mb_strtoupper(Str::random(4));

    $apiSystem = ApiSystem::firstWhere('canonical', 'binance')
        ?? ApiSystem::factory()->exchange()->create([
            'canonical' => 'binance',
            'name' => 'Binance',
        ]);

    $symbol = Symbol::factory()->create(['token' => $token]);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => $token,
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'margin_mode' => 'CROSSED',
    ]);

    return Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => $token.'USDT',
        'status' => 'active',
        'opening_price' => '1.00000000',
        'quantity' => '10.00000000',
        'leverage' => 10,
        'total_limit_orders' => 4,
    ]);
}

function seedPurgeTrail(Position $position, int $orderCount = 3): array
{
    $orderIds = [];
    for ($i = 0; $i < $orderCount; $i++) {
        $order = Order::withoutEvents(fn () => Order::create([
            'position_id' => $position->id,
            'uuid' => Str::uuid()->toString(),
            'client_order_id' => Str::uuid()->toString(),
            'exchange_order_id' => (string) random_int(10_000_000, 99_999_999),
            'type' => 'LIMIT',
            'side' => 'BUY',
            'position_side' => 'LONG',
            'status' => 'FILLED',
            'price' => '1.00000000',
            'quantity' => '10.00000000',
            'reference_price' => '1.00000000',
            'reference_quantity' => '10.00000000',
            'is_algo' => false,
        ]));
        $orderIds[] = $order->id;
    }

    DB::table('model_logs')->insert([
        'loggable_type' => Position::class,
        'loggable_id' => $position->id,
        'relatable_type' => Position::class,
        'relatable_id' => $position->id,
        'event_type' => 'updated',
        'attribute_name' => 'status',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('model_logs')->insert([
        'loggable_type' => Order::class,
        'loggable_id' => $orderIds[0],
        'relatable_type' => Order::class,
        'relatable_id' => $orderIds[0],
        'event_type' => 'updated',
        'attribute_name' => 'status',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('api_request_logs')->insert([
        'relatable_type' => Position::class,
        'relatable_id' => $position->id,
        'api_system_id' => $position->account->api_system_id,
        'http_response_code' => 200,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('api_request_logs')->insert([
        'relatable_type' => Order::class,
        'relatable_id' => $orderIds[1],
        'api_system_id' => $position->account->api_system_id,
        'http_response_code' => 200,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('api_snapshots')->insert([
        'responsable_type' => Position::class,
        'responsable_id' => $position->id,
        'canonical' => 'account-positions',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('steps')->insert([
        'block_uuid' => Str::uuid()->toString(),
        'class' => 'Kraite\\Core\\Jobs\\Atomic\\Position\\ActivatePositionJob',
        'state' => 'StepDispatcher\\States\\Completed',
        'relatable_type' => Position::class,
        'relatable_id' => $position->id,
        'queue' => 'positions',
        'arguments' => json_encode(['positionId' => $position->id]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('steps_archive')->insert([
        'block_uuid' => Str::uuid()->toString(),
        'class' => 'Kraite\\Core\\Jobs\\Atomic\\Position\\PreparePositionDataJob',
        'state' => 'StepDispatcher\\States\\Completed',
        'relatable_type' => Position::class,
        'relatable_id' => $position->id,
        'queue' => 'positions',
        'arguments' => json_encode(['positionId' => $position->id]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $orderIds;
}

it('dispatches the trail purge step when a position transitions to closed', function () {
    $position = buildClosablePosition();

    $position->update(['status' => 'closed']);

    // PurgePositionTrailJob is dispatched by PositionObserver inside a
    // `Steps::usingPrefix('trading')` scope so the breadcrumb janitor's
    // own row lands in `trading_steps`. Reads must scope through the
    // same prefix.
    $steps = Steps::usingPrefix('trading', fn () => Step::where('class', PurgePositionTrailJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->get());

    expect($steps)->toHaveCount(1)
        ->and($steps->first()->queue)->toBe('cronjobs');
});

it('does not dispatch the trail purge step when a position is cancelled', function () {
    $position = buildClosablePosition();

    $position->update(['status' => 'cancelled']);

    $count = Steps::usingPrefix('trading', fn (): int => Step::where('class', PurgePositionTrailJob::class)->count());
    expect($count)->toBe(0);
});

it('does not dispatch the trail purge step when a position fails', function () {
    $position = buildClosablePosition();

    $position->update(['status' => 'failed']);

    $count = Steps::usingPrefix('trading', fn (): int => Step::where('class', PurgePositionTrailJob::class)->count());
    expect($count)->toBe(0);
});

it('does not dispatch the trail purge step when an unrelated attribute changes on a closed position', function () {
    $position = buildClosablePosition();
    $position->update(['status' => 'closed']);
    Steps::usingPrefix('trading', fn () => Step::where('class', PurgePositionTrailJob::class)->delete());

    // Subsequent non-status update on the already-closed row must NOT
    // re-dispatch — wasChanged('status') is false on this save.
    $position->update(['quantity' => '11.00000000']);

    $count = Steps::usingPrefix('trading', fn (): int => Step::where('class', PurgePositionTrailJob::class)->count());
    expect($count)->toBe(0);
});

it('purges every breadcrumb tied to the position and its orders without touching the position row or orders', function () {
    $position = buildClosablePosition();
    $orderIds = seedPurgeTrail($position);

    // Sanity: every target table has at least one row before the purge.
    expect(DB::table('model_logs')->where('loggable_id', $position->id)->where('loggable_type', Position::class)->exists())->toBeTrue()
        ->and(DB::table('model_logs')->where('loggable_id', $orderIds[0])->where('loggable_type', Order::class)->exists())->toBeTrue()
        ->and(DB::table('api_request_logs')->where('relatable_id', $position->id)->where('relatable_type', Position::class)->exists())->toBeTrue()
        ->and(DB::table('api_request_logs')->where('relatable_id', $orderIds[1])->where('relatable_type', Order::class)->exists())->toBeTrue()
        ->and(DB::table('api_snapshots')->where('responsable_id', $position->id)->exists())->toBeTrue()
        ->and(DB::table('steps')->where('relatable_id', $position->id)->where('relatable_type', Position::class)->exists())->toBeTrue()
        ->and(DB::table('steps_archive')->where('relatable_id', $position->id)->where('relatable_type', Position::class)->exists())->toBeTrue();

    // Build a Step row for the janitor itself, sitting on a sibling
    // workflow_id so it doesn't share the position's relatable space —
    // the steps deletion will exclude it explicitly via $this->step->id.
    $janitorStepId = (int) DB::table('steps')->insertGetId([
        'block_uuid' => Str::uuid()->toString(),
        'class' => PurgePositionTrailJob::class,
        'state' => 'StepDispatcher\\States\\Running',
        // No relatable here — the janitor step shouldn't itself be
        // scoped to the position, since cleaning by relatable_id would
        // wipe it. The exclusion is a belt-and-braces guard.
        'queue' => 'cronjobs',
        'arguments' => json_encode(['positionId' => $position->id]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $job = new PurgePositionTrailJob($position->id);
    $job->step = Step::findOrFail($janitorStepId);
    $result = $job->compute();

    expect($result['position_id'])->toBe($position->id)
        ->and($result['orders_scanned'])->toBe(count($orderIds))
        ->and($result['deleted']['model_logs'])->toBeGreaterThanOrEqual(2)
        ->and($result['deleted']['api_request_logs'])->toBeGreaterThanOrEqual(2)
        ->and($result['deleted']['api_snapshots'])->toBeGreaterThanOrEqual(1)
        ->and($result['deleted']['steps'])->toBeGreaterThanOrEqual(1)
        ->and($result['deleted']['steps_archive'])->toBeGreaterThanOrEqual(1);

    // Breadcrumb tables fully wiped for this position.
    expect(DB::table('model_logs')->where('loggable_id', $position->id)->where('loggable_type', Position::class)->exists())->toBeFalse()
        ->and(DB::table('model_logs')->where('loggable_id', $orderIds[0])->where('loggable_type', Order::class)->exists())->toBeFalse()
        ->and(DB::table('api_request_logs')->where('relatable_id', $position->id)->where('relatable_type', Position::class)->exists())->toBeFalse()
        ->and(DB::table('api_request_logs')->where('relatable_id', $orderIds[1])->where('relatable_type', Order::class)->exists())->toBeFalse()
        ->and(DB::table('api_snapshots')->where('responsable_id', $position->id)->exists())->toBeFalse()
        ->and(DB::table('steps')->where('relatable_id', $position->id)->where('relatable_type', Position::class)->exists())->toBeFalse()
        ->and(DB::table('steps_archive')->where('relatable_id', $position->id)->where('relatable_type', Position::class)->exists())->toBeFalse();

    // Position + orders survive — they are the permanent record.
    expect(Position::find($position->id))->not->toBeNull()
        ->and(Order::whereIn('id', $orderIds)->count())->toBe(count($orderIds));
});

it('preserves its own running step row so the janitor can complete and be archived normally', function () {
    $position = buildClosablePosition();

    $runningStepId = (int) DB::table('steps')->insertGetId([
        'block_uuid' => Str::uuid()->toString(),
        'class' => PurgePositionTrailJob::class,
        'state' => 'StepDispatcher\\States\\Running',
        'relatable_type' => Position::class,
        'relatable_id' => $position->id,
        'queue' => 'cronjobs',
        'arguments' => json_encode(['positionId' => $position->id]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $siblingStepId = (int) DB::table('steps')->insertGetId([
        'block_uuid' => Str::uuid()->toString(),
        'class' => 'Kraite\\Core\\Jobs\\Atomic\\Position\\ActivatePositionJob',
        'state' => 'StepDispatcher\\States\\Completed',
        'relatable_type' => Position::class,
        'relatable_id' => $position->id,
        'queue' => 'positions',
        'arguments' => json_encode(['positionId' => $position->id]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $job = new PurgePositionTrailJob($position->id);
    $job->step = Step::findOrFail($runningStepId);
    $job->compute();

    expect(DB::table('steps')->where('id', $runningStepId)->exists())->toBeTrue()
        ->and(DB::table('steps')->where('id', $siblingStepId)->exists())->toBeFalse();
});
