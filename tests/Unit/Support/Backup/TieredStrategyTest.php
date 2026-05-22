<?php

declare(strict_types=1);

use App\Support\Backup\TieredStrategy;
use Carbon\Carbon;

/**
 * Pin the corruption-resilient retention selection.
 *
 * The strategy fan-outs into three tiers (hourly / daily / weekly).
 * These tests exercise `selectKeepPaths()` against synthetic backup
 * collections and assert that the right paths survive cleanup. The
 * `delete()` side effect is left out of scope — the public spatie
 * `deleteOldBackups()` entry point delegates to `selectKeepPaths()`
 * for the decision and only loops to actually delete.
 */

/**
 * Build a fake `Backup`-like object that exposes only the methods
 * `selectKeepPaths()` reads (`->path()` + `->date()`).
 */
function fakeBackup(string $path, string $isoDateTime): object
{
    return new class($path, $isoDateTime)
    {
        public function __construct(private string $path, private string $isoDateTime) {}

        public function path(): string
        {
            return $this->path;
        }

        public function date(): Carbon
        {
            return Carbon::parse($this->isoDateTime);
        }
    };
}

function buildStrategy(): TieredStrategy
{
    // `selectKeepPaths()` does not read any constructor state, so the
    // parent `CleanupStrategy` typed property is left uninitialised by
    // bypassing the constructor entirely. Reflection keeps the test
    // compatible with `final class TieredStrategy` (no subclass needed).
    return (new ReflectionClass(TieredStrategy::class))->newInstanceWithoutConstructor();
}

it('keeps the newest N as the hourly tier', function (): void {
    $strategy = buildStrategy();
    $now = Carbon::parse('2026-05-03 18:00:00');

    $sorted = collect([
        fakeBackup('h1', $now->copy()->subHour()->toIso8601String()),
        fakeBackup('h2', $now->copy()->subHours(2)->toIso8601String()),
        fakeBackup('h3', $now->copy()->subHours(3)->toIso8601String()),
        fakeBackup('h4', $now->copy()->subHours(4)->toIso8601String()),
    ]);

    $keep = $strategy->selectKeepPaths($sorted, hourlyCount: 3, dailyCount: 0, weeklyCount: 0);

    expect($keep)->toBe(['h1', 'h2', 'h3']);
});

it('falls back to daily-tier picks once the hourly tier is full', function (): void {
    $strategy = buildStrategy();

    // 3 hourlies on day-3 + 2 backups on day-2 + 1 on day-1 + 1 on day-0
    $sorted = collect([
        fakeBackup('day3-23', '2026-05-03 23:00:00'),
        fakeBackup('day3-12', '2026-05-03 12:00:00'),
        fakeBackup('day3-06', '2026-05-03 06:00:00'),
        fakeBackup('day2-23', '2026-05-02 23:00:00'),
        fakeBackup('day2-08', '2026-05-02 08:00:00'),
        fakeBackup('day1-23', '2026-05-01 23:00:00'),
        fakeBackup('day0-23', '2026-04-30 23:00:00'),
    ]);

    $keep = $strategy->selectKeepPaths($sorted, hourlyCount: 3, dailyCount: 3, weeklyCount: 0);

    // Hourly tier consumes day3's three. Daily tier picks newest of
    // day2, day1, day0 — three distinct days.
    expect($keep)->toBe(['day3-23', 'day3-12', 'day3-06', 'day2-23', 'day1-23', 'day0-23']);
});

it('skips days already represented in the hourly tier', function (): void {
    $strategy = buildStrategy();

    $sorted = collect([
        fakeBackup('day3-23', '2026-05-03 23:00:00'),
        fakeBackup('day3-22', '2026-05-03 22:00:00'),
        fakeBackup('day3-21', '2026-05-03 21:00:00'),
        // All three hourlies on day-3 → daily tier must NOT re-pick day-3.
        fakeBackup('day3-08', '2026-05-03 08:00:00'),
        fakeBackup('day2-23', '2026-05-02 23:00:00'),
        fakeBackup('day1-23', '2026-05-01 23:00:00'),
    ]);

    $keep = $strategy->selectKeepPaths($sorted, hourlyCount: 3, dailyCount: 3, weeklyCount: 0);

    expect($keep)->toBe(['day3-23', 'day3-22', 'day3-21', 'day2-23', 'day1-23']);
    expect($keep)->not->toContain('day3-08');
});

it('falls back to weekly-tier picks for long-tail snapshots', function (): void {
    $strategy = buildStrategy();

    // 3 hourlies + 3 dailies fill the first two tiers. Weekly tier
    // must reach back into older ISO weeks for grandfather coverage.
    $sorted = collect([
        fakeBackup('h1', '2026-05-03 18:00:00'), // ISO week 18 of 2026
        fakeBackup('h2', '2026-05-03 17:00:00'),
        fakeBackup('h3', '2026-05-03 16:00:00'),
        fakeBackup('d1', '2026-05-02 23:00:00'), // same ISO week
        fakeBackup('d2', '2026-05-01 23:00:00'), // same ISO week (Friday)
        fakeBackup('d3', '2026-04-30 23:00:00'), // same ISO week (Thursday)
        fakeBackup('w1', '2026-04-22 23:00:00'), // ISO week 17
        fakeBackup('w2', '2026-04-15 23:00:00'), // ISO week 16
        fakeBackup('w3', '2026-04-08 23:00:00'), // ISO week 15
        fakeBackup('w4', '2026-04-01 23:00:00'), // ISO week 14 — should be deleted
    ]);

    $keep = $strategy->selectKeepPaths($sorted, hourlyCount: 3, dailyCount: 3, weeklyCount: 3);

    expect($keep)->toBe(['h1', 'h2', 'h3', 'd1', 'd2', 'd3', 'w1', 'w2', 'w3']);
    expect($keep)->not->toContain('w4');
});

it('returns the newest backup when there is only one snapshot', function (): void {
    $strategy = buildStrategy();

    $sorted = collect([
        fakeBackup('only', '2026-05-03 18:00:00'),
    ]);

    $keep = $strategy->selectKeepPaths($sorted, hourlyCount: 3, dailyCount: 3, weeklyCount: 3);

    expect($keep)->toBe(['only']);
});

it('returns an empty list for an empty collection', function (): void {
    $strategy = buildStrategy();

    $keep = $strategy->selectKeepPaths(collect(), hourlyCount: 3, dailyCount: 3, weeklyCount: 3);

    expect($keep)->toBe([]);
});

it('accepts non-default tier counts', function (): void {
    $strategy = buildStrategy();

    $sorted = collect([
        fakeBackup('h1', '2026-05-03 18:00:00'),
        fakeBackup('h2', '2026-05-03 17:00:00'),
        fakeBackup('d1', '2026-05-02 23:00:00'),
        fakeBackup('d2', '2026-05-01 23:00:00'),
        fakeBackup('w1', '2026-04-22 23:00:00'),
    ]);

    // Tighter retention: 1 / 1 / 1.
    $keep = $strategy->selectKeepPaths($sorted, hourlyCount: 1, dailyCount: 1, weeklyCount: 1);

    expect($keep)->toBe(['h1', 'd1', 'w1']);
});

it('does not double-count a single backup across tiers', function (): void {
    $strategy = buildStrategy();

    // One backup per week for 5 weeks. Hourly tier 1 takes w0
    // (week 18). Daily tier 1 takes w1 (different day → week 17).
    // Weekly tier 2 takes w2 + w3 (weeks 16 + 15, neither already
    // picked). w4 (week 14) drops because the weekly cap is hit.
    $sorted = collect([
        fakeBackup('w0', '2026-05-03 18:00:00'),
        fakeBackup('w1', '2026-04-26 18:00:00'),
        fakeBackup('w2', '2026-04-19 18:00:00'),
        fakeBackup('w3', '2026-04-12 18:00:00'),
        fakeBackup('w4', '2026-04-05 18:00:00'),
    ]);

    $keep = $strategy->selectKeepPaths($sorted, hourlyCount: 1, dailyCount: 1, weeklyCount: 2);

    expect($keep)->toBe(['w0', 'w1', 'w2', 'w3']);
    expect($keep)->not->toContain('w4');
});
