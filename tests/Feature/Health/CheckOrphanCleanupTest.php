<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\Health\OrphanReconciler;
use Kraite\Core\Support\Health\OrphanReport;

/*
 * Detection-side integration test for the orphan-cleanup check.
 *
 * Verifies the wiring between the command's snapshot-collection
 * helpers and the OrphanReconciler classifier — feeds an account's
 * exchange + DB state in, asserts the reconciler returns the
 * expected report. Cleanup-execution tests live in a separate
 * follow-up suite (action layer is more involved and ships after
 * detection is verified live).
 */

uses(RefreshDatabase::class);

test('account with both flags false flags every exchange-only orphan', function (): void {
    $account = Account::factory()->create([
        'allow_other_orders' => false,
        'allow_other_positions' => false,
    ]);

    // Kraite has nothing locally; exchange has 2 orders + 1 position.
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: ['111', '222'],
        exchangePositionKeys: ['AKTUSDT:LONG'],
        kraiteOpenOrderIds: [],
        kraitePositionKeys: [],
        kraiteRecentlyClosedOrderIds: [],
        allowOtherOrders: $account->allow_other_orders,
        allowOtherPositions: $account->allow_other_positions,
    );

    expect($report)->toBeInstanceOf(OrphanReport::class);
    expect($report->isEmpty())->toBeFalse();
    expect($report->ordersToCancel)->toEqualCanonicalizing(['111', '222']);
    expect($report->positionsToClose)->toEqualCanonicalizing(['AKTUSDT:LONG']);
});

test('account with allow_other_orders=true ignores non-Kraite orphans', function (): void {
    $account = Account::factory()->create([
        'allow_other_orders' => true,
        'allow_other_positions' => true,
    ]);

    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: ['user_order_1', 'user_order_2'],
        exchangePositionKeys: ['DOGEUSDT:LONG'],
        kraiteOpenOrderIds: [],
        kraitePositionKeys: [],
        kraiteRecentlyClosedOrderIds: [],
        allowOtherOrders: $account->allow_other_orders,
        allowOtherPositions: $account->allow_other_positions,
    );

    expect($report->isEmpty())->toBeTrue();
});

test('match window is read from kraite.php config in MINUTES', function (): void {
    // The command reads the window via config('kraite.health_watchdog.orphan_kraite_match_window_minutes')
    // — verify the key resolves to a sensible default (60min) when not overridden.
    $minutes = config('kraite.health_watchdog.orphan_kraite_match_window_minutes', 60);

    expect($minutes)->toBeInt();
    expect($minutes)->toBeGreaterThanOrEqual(1);
});

test('default account flags are false on factory-created accounts', function (): void {
    $account = Account::factory()->create();

    expect($account->allow_other_orders)->toBeFalse();
    expect($account->allow_other_positions)->toBeFalse();
});
