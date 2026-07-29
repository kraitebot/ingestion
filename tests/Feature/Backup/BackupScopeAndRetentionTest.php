<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/**
 * Pin what the backup carries and how long it is kept.
 *
 * Production trigger (2026-07-28): six consecutive scheduled backups failed
 * with Backblaze brownouts mid-multipart-upload. Larger parts made the upload
 * survivable, but the dump was ~650 MB mostly because it carried diagnostic
 * stores — `api_request_logs` alone is 3.2 GB of a ~4 GB schema, and no
 * restore has ever needed an HTTP request log to recover trading state.
 *
 * Contract, both directions:
 *  - diagnostic stores stay OUT of the dump, so a brownout has a small target;
 *  - business and audit tables stay IN, because those are the whole point.
 *
 * The retention pin exists because the old 3/0/0 window was a storage-cost
 * decision made when dumps were large. A future edit shrinking it back would
 * silently return production to roughly nine hours of recoverable history.
 */
function dumpExcludedTables(): array
{
    return config('database.connections.'.config('backup.backup.source.databases')[0].'.dump.exclude_tables', []);
}

it('leaves diagnostic stores out of the dump', function (string $table): void {
    // `toContain` is variadic — a message here would be read as a second
    // expected value. The dataset name identifies the case instead.
    expect(dumpExcludedTables())->toContain($table);
})->with([
    'exchange HTTP call log' => 'api_request_logs',
    'exchange payload snapshots' => 'api_snapshots',
    'archived steps' => 'steps_archive',
    'archived trading steps' => 'trading_steps_archive',
    'dispatcher saturation samples' => 'steps_dispatcher_saturation',
    'slow query diagnostics' => 'slow_queries',
    'failed job records' => 'failed_jobs',
]);

it('keeps every table that a restore actually needs', function (string $table): void {
    expect(dumpExcludedTables())->not->toContain($table);
})->with([
    'accounts' => 'accounts',
    'positions' => 'positions',
    'orders' => 'orders',
    'users' => 'users',
    'balance history' => 'account_balance_history',
    'exchange income ledger' => 'account_incomes',
    'live steps' => 'steps',
    'notification audit trail' => 'notification_logs',
    'model audit log' => 'model_logs',
]);

it('never excludes a table that does not exist', function (): void {
    // A renamed or dropped table left in the list would silently stop
    // protecting whatever replaced it.
    foreach (dumpExcludedTables() as $table) {
        expect(Schema::hasTable($table))->toBeTrue("Excluded table [{$table}] no longer exists.");
    }
})->skip(fn (): bool => config('database.default') === 'sqlite', 'Needs the real schema.');

it('keeps enough history to recover from corruption noticed late', function (): void {
    // Three-hourly snapshots: 8 hourly is a full day, and the daily and
    // weekly tiers reach back two months.
    expect(config('kraite.backup_tiers.hourly'))->toBeGreaterThanOrEqual(8)
        ->and(config('kraite.backup_tiers.daily'))->toBeGreaterThanOrEqual(14)
        ->and(config('kraite.backup_tiers.weekly'))->toBeGreaterThanOrEqual(8);
});
