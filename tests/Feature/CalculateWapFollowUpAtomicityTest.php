<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Order\CalculateWapAndModifyProfitOrderJob;
use Kraite\Core\Jobs\Lifecycles\Position\ApplyWapJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\NotificationLog;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Notifications\Channels\AppPushChannel;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

/**
 * F14 regression (code-review 05-P1): the WAP follow-up block bulk-bumps
 * unacked FILLED LIMITs to reference_status=FILLED and then creates the
 * follow-up ApplyWap step. The comment always promised "same transaction";
 * the code didn't have one. If the ack committed and the step insert then
 * failed, the fills were permanently acknowledged with no follow-up WAP —
 * and the sync-orders recovery sweep (which re-discovers UNACKED fills)
 * was blinded, so nothing could ever heal it. The TP stayed sized for the
 * earlier fill set.
 *
 * Contract pinned here:
 *   1. ack + follow-up step commit together or not at all;
 *   2. after a failed attempt, the fills are still discoverable as
 *      unacked (the recovery sweep can re-fire);
 *   3. a successful run is idempotent — re-running complete() does not
 *      double-dispatch or find anything left to claim.
 */
$GLOBALS['forceApplyWapStepInsertFailure'] = false;

beforeEach(function (): void {
    $GLOBALS['forceApplyWapStepInsertFailure'] = false;

    // Seeded every test BEFORE any complete() runs: NotificationService
    // caches resolveNotification() in-process, so if the first test in
    // this file resolves the canonical against an empty table, the null
    // is cached and the notification test would fail vacuously.
    Kraite\Core\Models\Notification::query()->updateOrCreate(
        ['canonical' => 'position_penultimate_limit_filled'],
        [
            'title' => 'Penultimate Limit Filled',
            'description' => 'test',
            'default_severity' => 'high',
            'verified' => 1,
            'is_active' => true,
            'cache_duration' => 30,
            'cache_key' => ['position'],
        ],
    );

    // Registered once per process; the toggle scopes it per test. Throwing
    // from creating() aborts the insert — the exact shape of a transient
    // DB failure landing between the ack UPDATE and the step INSERT.
    static $registered = false;
    if (! $registered) {
        Step::creating(function (Step $step): void {
            if (($GLOBALS['forceApplyWapStepInsertFailure'] ?? false) && $step->class === ApplyWapJob::class) {
                throw new RuntimeException('forced follow-up step insert failure');
            }
        });
        $registered = true;
    }
});

function buildWapAtomicityFixture(string $token): array
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
        'price_precision' => 3,
        'quantity_precision' => 2,
        'tick_size' => '0.001',
    ]);
    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);
    $position = Position::factory()->long()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'status' => 'waping',
        'quantity' => '100',
        'opening_price' => '1.0',
        'profit_percentage' => '0.35',
        'total_limit_orders' => 4,
    ]);

    $profitOrder = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'PROFIT-LIMIT',
        'side' => 'SELL',
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '1.05',
        'reference_price' => '1.05',
        'quantity' => '100',
        'reference_quantity' => '100',
    ]));

    $unackedFill = Order::withoutEvents(fn () => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'type' => 'LIMIT',
        'side' => 'BUY',
        'status' => 'FILLED',
        'reference_status' => 'NEW',
        'price' => '0.95',
        'quantity' => '50',
    ]));

    return [$position, $profitOrder, $unackedFill];
}

function countFollowUpSteps(Position $position): int
{
    return Steps::usingPrefix('trading', fn (): int => (int) Step::query()
        ->where('class', ApplyWapJob::class)
        ->whereRaw("JSON_EXTRACT(arguments, '$.positionId') = ?", [$position->id])
        ->count());
}

function addFilledWapLimits(Position $position, int $count): void
{
    for ($index = 0; $index < $count; $index++) {
        Order::withoutEvents(fn () => Order::create([
            'position_id' => $position->id,
            'uuid' => Str::uuid()->toString(),
            'client_order_id' => Str::uuid()->toString(),
            'type' => 'LIMIT',
            'side' => 'BUY',
            'status' => 'FILLED',
            'reference_status' => 'FILLED',
            'price' => '0.94',
            'quantity' => '50',
        ]));
    }
}

it('rolls back the fill acknowledgement when the follow-up step insert fails', function (): void {
    [$position, $profitOrder, $unackedFill] = buildWapAtomicityFixture('WAPATOM1');

    $job = new CalculateWapAndModifyProfitOrderJob($position->id);
    $job->profitOrder = $profitOrder;

    $GLOBALS['forceApplyWapStepInsertFailure'] = true;

    expect(fn () => $job->complete())->toThrow(RuntimeException::class);

    // The ack must NOT survive the failed step insert — otherwise the
    // sync-orders recovery sweep can never rediscover these fills.
    expect($unackedFill->fresh()->reference_status)->not->toBe('FILLED')
        ->and(countFollowUpSteps($position))->toBe(0);
});

it('leaves the fills re-claimable: a retried complete() succeeds after a failed one', function (): void {
    [$position, $profitOrder, $unackedFill] = buildWapAtomicityFixture('WAPATOM2');

    $job = new CalculateWapAndModifyProfitOrderJob($position->id);
    $job->profitOrder = $profitOrder;

    $GLOBALS['forceApplyWapStepInsertFailure'] = true;
    expect(fn () => $job->complete())->toThrow(RuntimeException::class);

    // Transient gone — the retry must claim the fills AND create the step.
    $GLOBALS['forceApplyWapStepInsertFailure'] = false;
    $job->complete();

    expect($unackedFill->fresh()->reference_status)->toBe('FILLED')
        ->and(countFollowUpSteps($position))->toBe(1);
});

it('commits ack and follow-up step together on the happy path, idempotently', function (): void {
    [$position, $profitOrder, $unackedFill] = buildWapAtomicityFixture('WAPATOM3');

    $job = new CalculateWapAndModifyProfitOrderJob($position->id);
    $job->profitOrder = $profitOrder;

    $job->complete();

    expect($unackedFill->fresh()->reference_status)->toBe('FILLED')
        ->and(countFollowUpSteps($position))->toBe(1);

    // Second run: everything already claimed — nothing new to dispatch.
    $job->complete();

    expect(countFollowUpSteps($position))->toBe(1);
});

it('sends the penultimate-limit notification once and only through the app', function (): void {
    Illuminate\Support\Facades\Notification::fake();

    // The suite runs with the global notification switch off; this test
    // exists to count sends, so flip it on (Notification::fake absorbs).
    config(['kraite.notifications_enabled' => true]);

    [$position, $profitOrder] = buildWapAtomicityFixture('WAPNOTIF1');
    addFilledWapLimits($position, 2);

    $user = Kraite\Core\Models\User::factory()->create([
        'is_active' => true,
        'notification_channels' => ['mail'],
    ]);
    $position->account->update(['user_id' => $user->id]);

    $job = new CalculateWapAndModifyProfitOrderJob($position->id);
    $job->profitOrder = $profitOrder;

    $job->complete();

    Illuminate\Support\Facades\Cache::flush();

    (function (): void {
        /** @var CalculateWapAndModifyProfitOrderJob $this */
        $this->dispatchWapAppliedNotification('1.00', '100');
    })->call($job);

    Illuminate\Support\Facades\Notification::assertCount(1);
    Illuminate\Support\Facades\Notification::assertSentTo(
        $user,
        AlertNotification::class,
        static fn (AlertNotification $notification): bool => $notification->canonical === 'position_penultimate_limit_filled'
            && $notification->via($user) === [AppPushChannel::class],
    );
});

it('does not notify before the penultimate limit fills', function (): void {
    Illuminate\Support\Facades\Notification::fake();
    config(['kraite.notifications_enabled' => true]);

    [$position, $profitOrder] = buildWapAtomicityFixture('WAPNOTIF2');
    $user = Kraite\Core\Models\User::factory()->active()->create();
    $position->account->update(['user_id' => $user->id]);

    $job = new CalculateWapAndModifyProfitOrderJob($position->id);
    $job->profitOrder = $profitOrder;

    (function (): void {
        /** @var CalculateWapAndModifyProfitOrderJob $this */
        $this->dispatchWapAppliedNotification('1.00', '100');
    })->call($job);

    Illuminate\Support\Facades\Notification::assertNothingSent();
});

it('does not repeat the penultimate alert after the final limit also fills', function (): void {
    Illuminate\Support\Facades\Notification::fake();
    config(['kraite.notifications_enabled' => true]);

    [$position, $profitOrder] = buildWapAtomicityFixture('WAPNOTIF3');
    addFilledWapLimits($position, 3);

    $user = Kraite\Core\Models\User::factory()->active()->create();
    $position->account->update(['user_id' => $user->id]);

    NotificationLog::create([
        'canonical' => 'position_penultimate_limit_filled',
        'user_id' => $user->id,
        'relatable_type' => $position->getMorphClass(),
        'relatable_id' => $position->id,
        'channel' => 'app',
        'recipient' => 'iPhone app',
        'sent_at' => now(),
        'status' => 'delivered',
        'passed_threshold' => true,
    ]);

    $job = new CalculateWapAndModifyProfitOrderJob($position->id);
    $job->profitOrder = $profitOrder;

    (function (): void {
        /** @var CalculateWapAndModifyProfitOrderJob $this */
        $this->dispatchWapAppliedNotification('1.00', '100');
    })->call($job);

    Illuminate\Support\Facades\Notification::assertNothingSent();
});

it('formats the penultimate-limit notification with the token precisions and ladder depth', function (): void {
    Illuminate\Support\Facades\Notification::fake();
    config(['kraite.notifications_enabled' => true]);

    [$position, $profitOrder] = buildWapAtomicityFixture('WAPNOTIF4');
    addFilledWapLimits($position, 2);

    $user = Kraite\Core\Models\User::factory()->create([
        'is_active' => true,
        'notification_channels' => ['mail'],
    ]);
    $position->account->update(['user_id' => $user->id]);
    $profitOrder->forceFill([
        'price' => '6.66100000',
        'quantity' => '49.71000000',
    ]);

    $job = new CalculateWapAndModifyProfitOrderJob($position->id);
    $job->profitOrder = $profitOrder;
    $job->breakEvenPrice = '6.637363800000001';

    (function (): void {
        /** @var CalculateWapAndModifyProfitOrderJob $this */
        $this->dispatchWapAppliedNotification('7.05900000', '16.57000000');
    })->call($job);

    Illuminate\Support\Facades\Notification::assertSentTo(
        $user,
        AlertNotification::class,
        static fn (AlertNotification $notification): bool => $notification->canonical === 'position_penultimate_limit_filled'
            && str_contains((string) $notification->pushoverMessage, 'DCA rungs filled: 3/4')
            && str_contains((string) $notification->pushoverMessage, 'TP price: 7.059 → 6.661')
            && str_contains((string) $notification->pushoverMessage, 'TP qty: 16.57 → 49.71')
            && str_contains((string) $notification->pushoverMessage, 'Break-even: 6.637')
            && ! str_contains((string) $notification->pushoverMessage, '6.637363800000001'),
    );
});
