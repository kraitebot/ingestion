<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\VerifyIfTPIsFilledJob;
use Kraite\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

/**
 * F16 regression (code-review 05-P1): when the WAP verifier found the TP
 * already FILLED on the exchange, it threw immediately — discarding the
 * definitive fill knowledge, leaving the local order NEW and the position
 * reverting to 'active' (a phantom live position if WS/sync reconciliation
 * was degraded). The verifier now persists FILLED before aborting, which
 * must ripple through the OrderObserver's locked close path: order FILLED
 * → ClosePositionJob dispatched (deduped) → position claimed 'closing' →
 * the WAP resolver's revert-to-active no-ops on its waping-only guard.
 *
 * These tests pin the observer chain the fix rides on, from a 'waping'
 * position — the exact state the verifier runs in.
 */
function buildWapingPositionWithTp(string $token): array
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
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
    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);
    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'waping',
        'quantity' => '100',
        'opening_price' => '1.0',
        'total_limit_orders' => 4,
    ]);

    $tp = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'position_side' => 'LONG',
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '1.05',
        'quantity' => '100',
        'exchange_order_id' => 'tp-777',
    ]));

    return [$position, $tp];
}

function countCloseSteps(Position $position): int
{
    return Steps::usingPrefix('trading', fn (): int => (int) Step::query()
        ->where('class', ClosePositionJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->count());
}

it('persisting a TP fill from waping claims the position for close and dispatches once', function (): void {
    [$position, $tp] = buildWapingPositionWithTp('TPWAP1');

    // The exact write the verifier now performs in its FILLED branch.
    $tp->updateSaving(['status' => 'FILLED']);

    expect(countCloseSteps($position))->toBe(1)
        ->and($position->fresh()->status)->toBe('closing')
        ->and($tp->fresh()->reference_status)->toBe('FILLED')
        ->and($tp->fresh()->filled_at)->not->toBeNull();

    // Re-observing the same fill (sync tick catching up) must not
    // double-dispatch — reference_status now matches.
    $tp->fresh()->updateSaving(['status' => 'FILLED']);
    expect(countCloseSteps($position))->toBe(1);
});

it('the WAP resolver revert-to-active no-ops once close has claimed the position', function (): void {
    [$position, $tp] = buildWapingPositionWithTp('TPWAP2');

    $tp->updateSaving(['status' => 'FILLED']);
    expect($position->fresh()->status)->toBe('closing');

    // The WAP resolve-exception step: revert to 'active' ONLY from 'waping'.
    $job = new Kraite\Core\Jobs\Atomic\Position\UpdatePositionStatusJob(
        $position->id, 'active', 'WAP failed, reverting to active', 'waping'
    );
    $job->step = Step::create([
        'class' => Kraite\Core\Jobs\Atomic\Position\UpdatePositionStatusJob::class,
        'queue' => 'positions',
        'block_uuid' => (string) Str::uuid(),
        'index' => 1,
    ]);
    $job->compute();

    // Close ownership survives — no phantom revival to 'active'.
    expect($position->fresh()->status)->toBe('closing');
});

it('wires the persist-before-abort into the verifier source', function (): void {
    $source = file_get_contents((string) (new ReflectionClass(VerifyIfTPIsFilledJob::class))->getFileName());

    $persistPos = mb_strpos($source, "updateSaving(['status' => 'FILLED'])");
    $throwPos = mb_strpos($source, 'aborting WAP');

    expect($persistPos)->not->toBeFalse()
        ->and($throwPos)->not->toBeFalse()
        ->and($persistPos)->toBeLessThan($throwPos);
});
