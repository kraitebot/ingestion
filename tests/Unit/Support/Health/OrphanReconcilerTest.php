<?php

declare(strict_types=1);

use Kraite\Core\Support\Health\OrphanReconciler;

/*
 * OrphanReconciler is the pure-classification heart of the orphan-
 * cleanup watchdog. Given a snapshot of:
 *
 *   - what's currently on the exchange (open orders by exchange id +
 *     open positions by symbol:direction key + algo orders by algo id)
 *   - what Kraite has locally (non-terminal Order client_order_ids on
 *     opened() positions; the set of opened() position keys; the set
 *     of client_order_ids attached to recently-closed Kraite positions
 *     within the configurable match window)
 *   - the per-account `allow_other_positions` / `allow_other_orders`
 *     flags
 *
 * it returns a structured report listing:
 *
 *   - exchange order ids to cancel
 *   - exchange position keys (symbol:direction) to close
 *
 * No DB queries, no API calls, no Step dispatch. Pure decision
 * logic — easy to test in isolation, easy to reason about.
 */

test('returns empty report when exchange is empty', function (): void {
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: [],
        exchangePositionKeys: [],
        kraiteOpenOrderIds: ['111', '222'],
        kraitePositionKeys: ['BTCUSDT:LONG'],
        kraiteRecentlyClosedOrderIds: ['999'],
        allowOtherOrders: false,
        allowOtherPositions: false,
    );

    expect($report->ordersToCancel)->toBe([]);
    expect($report->positionsToClose)->toBe([]);
});

test('returns empty when exchange state matches Kraite state exactly', function (): void {
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: ['111', '222'],
        exchangePositionKeys: ['BTCUSDT:LONG'],
        kraiteOpenOrderIds: ['111', '222'],
        kraitePositionKeys: ['BTCUSDT:LONG'],
        kraiteRecentlyClosedOrderIds: [],
        allowOtherOrders: false,
        allowOtherPositions: false,
    );

    expect($report->ordersToCancel)->toBe([]);
    expect($report->positionsToClose)->toBe([]);
});

test('with both flags false, cancels all exchange orders not in Kraite open set', function (): void {
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: ['111', '222', '333', '444'],
        exchangePositionKeys: [],
        kraiteOpenOrderIds: ['111', '222'],
        kraitePositionKeys: [],
        kraiteRecentlyClosedOrderIds: [],
        allowOtherOrders: false,
        allowOtherPositions: false,
    );

    expect($report->ordersToCancel)->toEqualCanonicalizing(['333', '444']);
});

test('with both flags false, closes all exchange positions not in Kraite opened set', function (): void {
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: [],
        exchangePositionKeys: ['BTCUSDT:LONG', 'ETHUSDT:SHORT', 'AKTUSDT:LONG'],
        kraiteOpenOrderIds: [],
        kraitePositionKeys: ['BTCUSDT:LONG', 'ETHUSDT:SHORT'],
        kraiteRecentlyClosedOrderIds: [],
        allowOtherOrders: false,
        allowOtherPositions: false,
    );

    expect($report->positionsToClose)->toEqualCanonicalizing(['AKTUSDT:LONG']);
});

test('with allow_other_orders=true, cancels only Kraite-leftover orphans', function (): void {
    // Exchange has 4 unmatched orders. Two are Kraite leftovers (matched
    // to recently-closed Kraite position client_order_ids). The other
    // two are user-placed and stay untouched.
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: ['111', '222', '333', '444'],
        exchangePositionKeys: [],
        kraiteOpenOrderIds: [],
        kraitePositionKeys: [],
        kraiteRecentlyClosedOrderIds: ['111', '222'],
        allowOtherOrders: true,
        allowOtherPositions: false,
    );

    expect($report->ordersToCancel)->toEqualCanonicalizing(['111', '222']);
});

test('with allow_other_orders=true and no recent Kraite closes, cancels nothing', function (): void {
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: ['111', '222', '333'],
        exchangePositionKeys: [],
        kraiteOpenOrderIds: [],
        kraitePositionKeys: [],
        kraiteRecentlyClosedOrderIds: [],
        allowOtherOrders: true,
        allowOtherPositions: false,
    );

    expect($report->ordersToCancel)->toBe([]);
});

test('with allow_other_positions=true, ignores all orphan positions', function (): void {
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: [],
        exchangePositionKeys: ['BTCUSDT:LONG', 'ETHUSDT:SHORT', 'AKTUSDT:LONG'],
        kraiteOpenOrderIds: [],
        kraitePositionKeys: ['BTCUSDT:LONG'],
        kraiteRecentlyClosedOrderIds: [],
        allowOtherOrders: false,
        allowOtherPositions: true,
    );

    expect($report->positionsToClose)->toBe([]);
});

test('mixed scenario — both true, only Kraite-leftover orders, no positions touched', function (): void {
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: ['111', '222', '333'],
        exchangePositionKeys: ['BTCUSDT:LONG', 'AKTUSDT:LONG'],
        kraiteOpenOrderIds: ['111'],
        kraitePositionKeys: ['BTCUSDT:LONG'],
        kraiteRecentlyClosedOrderIds: ['222'],
        allowOtherOrders: true,
        allowOtherPositions: true,
    );

    expect($report->ordersToCancel)->toBe(['222']);
    expect($report->positionsToClose)->toBe([]);
});

test('orders attached to currently-open Kraite positions are never flagged as orphans', function (): void {
    // Order 111 is on the exchange AND in our active orders set →
    // never appears in cancel list regardless of flag combinations.
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: ['111'],
        exchangePositionKeys: [],
        kraiteOpenOrderIds: ['111'],
        kraitePositionKeys: [],
        kraiteRecentlyClosedOrderIds: ['111'],
        allowOtherOrders: false,
        allowOtherPositions: false,
    );

    expect($report->ordersToCancel)->toBe([]);
});

test('hasInflightPositions=true suppresses order-orphan detection but keeps position-orphan detection active', function (): void {
    // Reproduces the 2026-05-03 false-positive on ETCUSDT/LONG: a
    // position was being opened, its limit ladder was being placed
    // on the exchange, but the local Order rows had not yet received
    // their `exchange_order_id` writes. The orphan classifier saw
    // exchange orders A/B/C with no local match → flagged as orphans
    // → cancelled legitimate working orders. The in-flight guard
    // skips the order-cancel decision entirely while any position
    // on the account is mid-creation.
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: ['111', '222', '333'],
        exchangePositionKeys: ['ETCUSDT:LONG', 'BTCUSDT:LONG'],
        kraiteOpenOrderIds: [], // empty — local Order rows still being written
        kraitePositionKeys: ['ETCUSDT:LONG'], // ETC matched, BTC not
        kraiteRecentlyClosedOrderIds: [],
        allowOtherOrders: false,
        allowOtherPositions: false,
        hasInflightPositions: true,
    );

    // Order-orphan check skipped → empty.
    expect($report->ordersToCancel)->toBe([]);
    // Position-orphan check still runs — BTCUSDT:LONG has no local
    // counterpart, gets flagged.
    expect($report->positionsToClose)->toBe(['BTCUSDT:LONG']);
});

test('hasInflightPositions=false (default) preserves prior behaviour', function (): void {
    // Same input as the test above but with the flag off — order
    // classification fires normally. Pinned to confirm the new
    // parameter defaults to off and does not regress the existing
    // contract.
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: ['111', '222', '333'],
        exchangePositionKeys: ['ETCUSDT:LONG', 'BTCUSDT:LONG'],
        kraiteOpenOrderIds: [],
        kraitePositionKeys: ['ETCUSDT:LONG'],
        kraiteRecentlyClosedOrderIds: [],
        allowOtherOrders: false,
        allowOtherPositions: false,
    );

    expect($report->ordersToCancel)->toEqualCanonicalizing(['111', '222', '333']);
    expect($report->positionsToClose)->toBe(['BTCUSDT:LONG']);
});

test('with allow_other_positions=true, ignores truly foreign positions', function (): void {
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: [],
        exchangePositionKeys: ['DOGEUSDT:LONG'],
        kraiteOpenOrderIds: [],
        kraitePositionKeys: [],
        kraiteRecentlyClosedOrderIds: [],
        allowOtherOrders: true,
        allowOtherPositions: true,
        kraiteRecentlyClosedPositionKeys: [],
    );

    expect($report->positionsToClose)->toBe([]);
});

test('with allow_other_positions=true, closes a Kraite leftover matching a recently-closed position key', function (): void {
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: [],
        exchangePositionKeys: ['ETHUSDT:SHORT', 'DOGEUSDT:LONG'],
        kraiteOpenOrderIds: [],
        kraitePositionKeys: [],
        kraiteRecentlyClosedOrderIds: [],
        allowOtherOrders: true,
        allowOtherPositions: true,
        kraiteRecentlyClosedPositionKeys: ['ETHUSDT:SHORT'],
    );

    expect($report->positionsToClose)->toBe(['ETHUSDT:SHORT']);
});

test('with allow_other_positions=false, recent-position keys change nothing — all unknowns close', function (): void {
    $report = OrphanReconciler::reconcile(
        exchangeOpenOrderIds: [],
        exchangePositionKeys: ['ETHUSDT:SHORT', 'DOGEUSDT:LONG'],
        kraiteOpenOrderIds: [],
        kraitePositionKeys: [],
        kraiteRecentlyClosedOrderIds: [],
        allowOtherOrders: false,
        allowOtherPositions: false,
        kraiteRecentlyClosedPositionKeys: ['ETHUSDT:SHORT'],
    );

    expect($report->positionsToClose)->toEqualCanonicalizing(['ETHUSDT:SHORT', 'DOGEUSDT:LONG']);
});
