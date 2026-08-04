<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Exceptions\NonNotifiableException;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;

/**
 * Pin the OrderObserver creation slot guard. The guard refuses to
 * insert a SECOND active row of a singleton type — duplicate live
 * MARKET / STOP-MARKET / TP orders are the symptom that recreated
 * the BSB #109 + ETC #211 incidents. Each guard branch ships its
 * NonNotifiableException so the caller (PrepareOrderCorrectionJob,
 * SmartReplaceOrdersJob, PlacePositionTpsl) can soft-resolve.
 *
 *   - MARKET: 1 per position.
 *   - STOP-MARKET: 1 per position.
 *   - PROFIT-LIMIT / PROFIT-MARKET: 1 per position (shared "PROFIT"
 *     slot — guard message normalised to "PROFIT").
 *   - LIMIT: capped by position->total_limit_orders.
 *
 * INACTIVE_STATUSES = [CANCELLED, EXPIRED, REJECTED] — those rows DO NOT
 * count toward the slot. (Note: FILLED MARKET still counts as
 * "occupying" the slot in the current implementation — only
 * terminal non-fill states free a slot.)
 */
function makeSlotOrder(Position $position, array $attrs = []): ?Order
{
    return Order::create(array_merge([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'side' => 'BUY',
        'position_side' => $position->direction,
        'type' => 'LIMIT',
        'price' => '0.10',
        'quantity' => '100',
        'status' => 'NEW',
    ], $attrs));
}

it('rejects a second active MARKET on the same position', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, ['type' => 'MARKET', 'status' => 'NEW']);

    expect(fn () => makeSlotOrder($position, ['type' => 'MARKET', 'status' => 'NEW']))
        ->toThrow(NonNotifiableException::class);
});

it('rejects a second active STOP-MARKET on the same position', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, ['type' => 'STOP-MARKET', 'side' => 'SELL', 'price' => '0.05', 'status' => 'NEW']);

    expect(fn () => makeSlotOrder($position, ['type' => 'STOP-MARKET', 'side' => 'SELL', 'price' => '0.05', 'status' => 'NEW']))
        ->toThrow(NonNotifiableException::class);
});

it('rejects a second active PROFIT-LIMIT on the same position', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, ['type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'price' => '0.20', 'status' => 'NEW']);

    expect(fn () => makeSlotOrder($position, ['type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'price' => '0.20', 'status' => 'NEW']))
        ->toThrow(NonNotifiableException::class);
});

it('rejects PROFIT-MARKET when a PROFIT-LIMIT already occupies the shared TP slot (unified PROFIT cap)', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, ['type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'price' => '0.20', 'status' => 'NEW']);

    expect(fn () => makeSlotOrder($position, ['type' => 'PROFIT-MARKET', 'side' => 'SELL', 'price' => '0.20', 'status' => 'NEW']))
        ->toThrow(NonNotifiableException::class, 'PROFIT order creation blocked');
});

it('admits a new LIMIT until total_limit_orders is reached', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 3]);

    makeSlotOrder($position, ['type' => 'LIMIT', 'price' => '0.09']);
    makeSlotOrder($position, ['type' => 'LIMIT', 'price' => '0.08']);
    makeSlotOrder($position, ['type' => 'LIMIT', 'price' => '0.07']);

    expect(fn () => makeSlotOrder($position, ['type' => 'LIMIT', 'price' => '0.06']))
        ->toThrow(NonNotifiableException::class);
});

it('admits a new MARKET when the prior MARKET is CANCELLED (slot freed)', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, ['type' => 'MARKET', 'status' => 'CANCELLED']);

    $second = makeSlotOrder($position, ['type' => 'MARKET', 'status' => 'NEW']);

    expect($second)->not->toBeNull()
        ->and($second->id)->toBeGreaterThan(0);
});

it('admits a new STOP-MARKET when the prior STOP-MARKET is EXPIRED (slot freed)', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, ['type' => 'STOP-MARKET', 'side' => 'SELL', 'price' => '0.05', 'status' => 'EXPIRED']);

    $second = makeSlotOrder($position, ['type' => 'STOP-MARKET', 'side' => 'SELL', 'price' => '0.05', 'status' => 'NEW']);

    expect($second)->not->toBeNull();
});

it('admits a new protection order when the prior order is REJECTED', function (string $type): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, [
        'type' => $type,
        'side' => 'SELL',
        'price' => '0.05',
        'status' => 'REJECTED',
    ]);

    $replacement = makeSlotOrder($position, [
        'type' => $type,
        'side' => 'SELL',
        'price' => '0.05',
        'status' => 'NEW',
    ]);

    expect($replacement)->not->toBeNull()
        ->and($replacement->status)->toBe('NEW');
})->with([
    'take profit' => 'PROFIT-LIMIT',
    'stop loss' => 'STOP-MARKET',
]);

it('admits unrelated type after a MARKET is in flight (slots are per-type)', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    makeSlotOrder($position, ['type' => 'MARKET', 'status' => 'NEW']);

    $tp = makeSlotOrder($position, ['type' => 'PROFIT-LIMIT', 'side' => 'SELL', 'price' => '0.20', 'status' => 'NEW']);
    $sl = makeSlotOrder($position, ['type' => 'STOP-MARKET', 'side' => 'SELL', 'price' => '0.05', 'status' => 'NEW']);
    $limit = makeSlotOrder($position, ['type' => 'LIMIT', 'price' => '0.09', 'status' => 'NEW']);

    expect($tp)->not->toBeNull()
        ->and($sl)->not->toBeNull()
        ->and($limit)->not->toBeNull();
});

it('locks the parent position before checking and inserting an order slot', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    $queries = [];

    DB::listen(function ($query) use (&$queries): void {
        $queries[] = mb_strtolower($query->sql);
    });

    Order::createForPosition([
        'position_id' => $position->id,
        'type' => 'STOP-MARKET',
        'status' => 'NEW',
        'side' => 'SELL',
        'position_side' => $position->direction,
        'price' => '0.05',
        'quantity' => '100',
    ]);

    $lockIndex = collect($queries)->search(
        fn (string $sql): bool => str_contains($sql, 'from `positions`') && str_contains($sql, 'for update')
    );
    $insertIndex = collect($queries)->search(
        fn (string $sql): bool => str_starts_with($sql, 'insert into `orders`')
    );

    expect($lockIndex)->not->toBeFalse()
        ->and($insertIndex)->not->toBeFalse()
        ->and($lockIndex)->toBeLessThan($insertIndex);
});

it('rejects a competing order committed after an outer transaction snapshot was created', function (): void {
    $position = Position::factory()->long()->create(['total_limit_orders' => 4]);
    $account = $position->account;
    $writerConnectionName = 'order_slot_race_writer';
    $writerOrderUuid = (string) Str::uuid();
    $caught = null;

    config()->set(
        "database.connections.{$writerConnectionName}",
        config('database.connections.'.DB::getDefaultConnection()),
    );
    DB::purge($writerConnectionName);
    $writer = DB::connection($writerConnectionName);

    // Expose only this test's factory graph to the independent connection.
    DB::commit();

    try {
        DB::beginTransaction();

        expect(Order::query()
            ->where('position_id', $position->id)
            ->where('type', 'MARKET')
            ->exists())->toBeFalse();

        $writer->table('orders')->insert([
            'position_id' => $position->id,
            'uuid' => $writerOrderUuid,
            'client_order_id' => (string) Str::uuid(),
            'type' => 'MARKET',
            'status' => 'NEW',
            'side' => 'BUY',
            'position_side' => $position->direction,
            'price' => '0.10',
            'quantity' => '100',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        expect($writer->table('orders')->where('uuid', $writerOrderUuid)->count())->toBe(1);

        try {
            Order::createForPosition([
                'position_id' => $position->id,
                'type' => 'MARKET',
                'status' => 'NEW',
                'side' => 'BUY',
                'position_side' => $position->direction,
                'price' => '0.11',
                'quantity' => '90',
            ]);
        } catch (Throwable $exception) {
            $caught = $exception;
        }

        DB::rollBack();

        expect($writer->table('orders')->where('uuid', $writerOrderUuid)->count())->toBe(1);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        $writer->table('orders')->where('position_id', $position->id)->delete();
        $writer->table('positions')->where('id', $position->id)->delete();
        $writer->table('accounts')->where('id', $account->id)->delete();
        $writer->table('users')->where('id', $account->user_id)->delete();
        $writer->table('api_systems')->where('id', $account->api_system_id)->delete();
        $writer->table('trade_configuration')->where('id', $account->trade_configuration_id)->delete();
        $writer->table('kraite')->where('id', 1)->delete();
        DB::disconnect($writerConnectionName);

        // RefreshDatabase expects to roll back its managed transaction.
        DB::beginTransaction();
    }

    expect($caught)->toBeInstanceOf(NonNotifiableException::class);
});

it('routes every production order insert through the locked creation boundary', function (): void {
    $sourceRoot = realpath(base_path('vendor/kraitebot/core/src'));

    expect($sourceRoot)->not->toBeFalse();

    $unsafeCreationSites = collect(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot))
    )
        ->filter(fn (SplFileInfo $file): bool => $file->isFile() && $file->getExtension() === 'php')
        ->reject(fn (SplFileInfo $file): bool => $file->getRealPath() === (new ReflectionClass(Order::class))->getFileName())
        ->filter(function (SplFileInfo $file): bool {
            $source = file_get_contents($file->getRealPath());
            $executableSource = collect(token_get_all($source))
                ->reject(fn (array|string $token): bool => is_array($token)
                    && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true))
                ->map(fn (array|string $token): string => is_array($token) ? $token[1] : $token)
                ->implode('');
            $unsafePatterns = [
                '/\bOrder::(?:query\(\)->)?(?:create|createQuietly|forceCreate|firstOrCreate|updateOrCreate|insert|insertOrIgnore|upsert)\s*\(/',
                '/\bnew\s+Order\b/',
                '/->orders\(\)->(?:create|save|firstOrCreate|updateOrCreate)\s*\(/',
                '/DB::table\([\'\"]orders[\'\"]\)->(?:insert|insertOrIgnore|upsert)\s*\(/',
            ];

            return collect($unsafePatterns)->contains(
                fn (string $pattern): bool => preg_match($pattern, $executableSource) === 1,
            );
        })
        ->map(fn (SplFileInfo $file): string => str_replace($sourceRoot.'/', '', $file->getRealPath()))
        ->values()
        ->all();

    expect($unsafeCreationSites)->toBe([]);
});
