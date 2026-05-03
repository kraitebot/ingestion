<?php

declare(strict_types=1);

namespace App\Support\Backup;

use Illuminate\Support\Collection;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupCollection;
use Spatie\Backup\Tasks\Cleanup\CleanupStrategy;

/**
 * TieredStrategy
 *
 * Corruption-resilient retention. Hourly snapshots stack into three
 * non-overlapping recovery windows so that an undetected corruption
 * window never wipes the safety net:
 *
 *   - Son         — newest N hourly snapshots (last few hours)
 *   - Father      — newest N daily snapshots (last few days)
 *   - Grandfather — newest N weekly snapshots (last few weeks)
 *
 * Bucketing rules:
 *
 *   - "Hourly bucket" is just the most recent N backups by date,
 *     regardless of which hour they fall in.
 *   - "Daily bucket" picks the newest backup per UTC day, skipping
 *     any day that already has a backup picked by the hourly bucket.
 *     Capped at N distinct days.
 *   - "Weekly bucket" picks the newest backup per ISO week-year,
 *     skipping any week already represented in the hourly or daily
 *     buckets. Capped at N distinct weeks.
 *
 * Anything outside the three buckets is deleted.
 *
 * Tier counts are configurable via `kraite.backup_tiers.{hourly,
 * daily,weekly}`. Defaults: 3 / 3 / 3.
 *
 * Spatie's contract guarantees `$backups` is sorted newest-first
 * when `deleteOldBackups()` is invoked, but this class re-sorts
 * defensively in case of contract drift.
 */
class TieredStrategy extends CleanupStrategy
{
    public function deleteOldBackups(BackupCollection $backups): void
    {
        $sorted = $backups->sortByDesc(fn (Backup $backup) => $backup->date()->getTimestamp())->values();

        if ($sorted->isEmpty()) {
            return;
        }

        $hourlyCount = (int) config('kraite.backup_tiers.hourly', 3);
        $dailyCount = (int) config('kraite.backup_tiers.daily', 3);
        $weeklyCount = (int) config('kraite.backup_tiers.weekly', 3);

        $keepPaths = $this->selectKeepPaths($sorted, $hourlyCount, $dailyCount, $weeklyCount);

        foreach ($sorted as $backup) {
            if (in_array($backup->path(), $keepPaths, true)) {
                continue;
            }

            $backup->delete();
        }
    }

    /**
     * Pick the paths that survive cleanup.
     *
     * @param  Collection<int, Backup>  $sorted  newest-first
     * @return list<string>
     */
    public function selectKeepPaths(
        Collection $sorted,
        int $hourlyCount,
        int $dailyCount,
        int $weeklyCount,
    ): array {
        $keep = [];
        $usedDays = [];
        $usedWeeks = [];

        // Son tier — newest N regardless of when.
        foreach ($sorted as $backup) {
            if (count($keep) >= $hourlyCount) {
                break;
            }

            $keep[$backup->path()] = true;
            $usedDays[$backup->date()->format('Y-m-d')] = true;
            $usedWeeks[$backup->date()->format('o-W')] = true;
        }

        // Father tier — newest backup per UTC day not already in
        // the son tier, capped at N distinct days.
        $dailyPicks = 0;
        foreach ($sorted as $backup) {
            if ($dailyPicks >= $dailyCount) {
                break;
            }

            $day = $backup->date()->format('Y-m-d');
            if (isset($usedDays[$day])) {
                continue;
            }

            $keep[$backup->path()] = true;
            $usedDays[$day] = true;
            $usedWeeks[$backup->date()->format('o-W')] = true;
            $dailyPicks++;
        }

        // Grandfather tier — newest backup per ISO week-year not
        // already represented, capped at N distinct weeks.
        $weeklyPicks = 0;
        foreach ($sorted as $backup) {
            if ($weeklyPicks >= $weeklyCount) {
                break;
            }

            $week = $backup->date()->format('o-W');
            if (isset($usedWeeks[$week])) {
                continue;
            }

            $keep[$backup->path()] = true;
            $usedWeeks[$week] = true;
            $weeklyPicks++;
        }

        return array_keys($keep);
    }
}
