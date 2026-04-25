<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Models\Account\AssignBestTokensToPositionSlotsJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\TradeConfiguration;

/**
 * `AssignBestTokensToPositionSlotsJob::createPositionSlots()` atomic
 * slot reservation contract.
 *
 * The 2026-04-25 17:33 incident produced 2 SHORT positions (#241, #242)
 * when only 1 SHORT slot was available (cap=6, existing=5). Two
 * concurrent `PreparePositionsOpeningJob` runs each fired their own
 * `AssignBest`, both read the same pre-state (`dbShorts=5`), both
 * computed `availableShortSlots=1`, both inserted — net +2 positions,
 * slot cap silently breached.
 *
 * This is a textbook check-then-act race: the read-count and the
 * INSERT happen in two separate auto-committed statements with no
 * lock holding the slot calculation invariant. Two transactions
 * doing that read at the same wall-clock instant both see the same
 * pre-state and both proceed.
 *
 * Contract: the slot calculation + position INSERTs must run inside
 * a single `DB::transaction()` with a row-level `lockForUpdate()` on
 * the `accounts` row. Concurrent runs serialise on that lock; the
 * second one reads the post-commit count and finds zero available.
 *
 * Two layers of pinning below — a source-level guard so a future
 * refactor can't quietly drop the lock, and a behavioural test that
 * the slot math holds when the account is one slot below cap.
 */
function buildAtomicSlotTestAccount(int $maxLongs, int $maxShorts): Account
{
    $apiSystem = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        [
            'name' => 'Binance',
            'is_exchange' => true,
        ]
    );

    $tradeConfig = TradeConfiguration::firstOrCreate(
        ['is_default' => true],
        [
            'canonical' => 'default',
            'description' => 'Default configuration',
            'least_timeframe_index_to_change_indicator' => 3,
            'fast_trade_position_duration_seconds' => 600,
            'fast_trade_position_closed_age_seconds' => 3600,
            'disable_exchange_symbol_from_negative_pnl_position' => false,
        ]
    );

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'trade_configuration_id' => $tradeConfig->id,
        'can_trade' => true,
        'total_positions_long' => $maxLongs,
        'total_positions_short' => $maxShorts,
    ]);
}

it('wraps createPositionSlots in a DB transaction with lockForUpdate on the accounts row', function (): void {
    // Source-level pin. A future refactor that quietly drops the
    // pessimistic lock re-opens the slot-cap race that bit us on
    // 2026-04-25 17:33. CLAUDE.md mandates this pattern — pin it
    // so the rule is enforceable, not just documented.
    $reflection = new ReflectionMethod(AssignBestTokensToPositionSlotsJob::class, 'createPositionSlots');
    $file = (new ReflectionClass(AssignBestTokensToPositionSlotsJob::class))->getFileName();
    $body = implode('', array_slice(file($file), $reflection->getStartLine() - 1, $reflection->getEndLine() - $reflection->getStartLine() + 1));

    expect($body)->toMatch(
        '/DB::transaction\s*\(/',
        'createPositionSlots must run its read-count + insert sequence inside a single '
        .'DB::transaction so concurrent runs serialise on the lock instead of each '
        .'reading the same stale slot count.'
    );

    expect($body)->toMatch(
        '/lockForUpdate\s*\(\s*\)/',
        'The transaction must acquire a pessimistic row-level lock on the accounts row '
        .'(via lockForUpdate) before reading slot counts. Without the lock, two parallel '
        .'AssignBest runs both pass the count check and both insert — slot cap breached.'
    );
});

it('does not exceed the SHORT slot cap when an account starts one slot below cap', function (): void {
    $account = buildAtomicSlotTestAccount(maxLongs: 0, maxShorts: 6);

    // Seed 5 existing 'active' SHORTs — exactly one slot below cap.
    Position::factory()
        ->count(5)
        ->create([
            'account_id' => $account->id,
            'direction' => 'SHORT',
            'status' => 'active',
        ]);

    $job = new AssignBestTokensToPositionSlotsJob($account->id);

    // Run createPositionSlots directly to test the slot-reservation
    // primitive in isolation (skip token assignment, no exchange API
    // calls, no test fixtures for token discovery).
    $result = $job->createPositionSlots();

    expect($result['available_slots']['shorts'])->toBe(
        1,
        'With 5 existing SHORTs and cap=6, exactly 1 slot must be reported available.'
    );

    expect($job->totalCreated)->toBe(
        1,
        'Exactly 1 new SHORT position must be created — not 0 (would mean the cap math '
        .'is wrong) and not 2 (would mean the slot cap was breached, which is the '
        .'2026-04-25 17:33 race outcome).'
    );

    $totalShorts = $account->positions()
        ->where('direction', 'SHORT')
        ->whereIn('status', ['active', 'new', 'opening', 'waping', 'syncing', 'closing', 'cancelling'])
        ->count();

    expect($totalShorts)->toBe(
        6,
        'Final SHORT count must be exactly the cap (6). Anything above means the '
        .'reservation lost the cap invariant.'
    );
});
