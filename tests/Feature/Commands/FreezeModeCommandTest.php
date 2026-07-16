<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Kraite\Core\Support\FreezeMode;
use Kraite\Core\Support\FrozenOperationalData;
use Kraite\Core\Support\MaintenanceMode;

beforeEach(function (): void {
    $suffix = Str::uuid()->toString();

    config([
        'kraite.freeze.marker_path' => storage_path("framework/testing/kraite-frozen-{$suffix}"),
        'kraite.freeze.logs_path' => storage_path("framework/testing/kraite-freeze-logs-{$suffix}"),
    ]);

    File::delete(FreezeMode::markerPath());
    File::deleteDirectory(config('kraite.freeze.logs_path'));
    MaintenanceMode::resumeAllStepsDispatch();
});

afterEach(function (): void {
    File::delete(FreezeMode::markerPath());
    File::deleteDirectory(config('kraite.freeze.logs_path'));
    MaintenanceMode::resumeAllStepsDispatch();
});

it('freezes durably and is idempotent', function (): void {
    expect(FreezeMode::isActive())->toBeFalse();

    $this->artisan('kraite:freeze')
        ->expectsOutputToContain('FROZEN')
        ->assertSuccessful();

    expect(FreezeMode::isActive())
        ->toBeTrue()
        ->and(File::get(FreezeMode::markerPath()))
        ->toContain('frozen_at');

    $this->artisan('kraite:freeze')->assertSuccessful();

    expect(FreezeMode::isActive())->toBeTrue();
});

it('refuses a non-interactive unfreeze while protected data exists', function (): void {
    FreezeMode::activate();

    DB::table('trading_steps')->insert([
        'block_uuid' => Str::uuid()->toString(),
        'state' => 'Pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('kraite:unfreeze', ['--no-interaction' => true])
        ->expectsOutputToContain('refused')
        ->assertFailed();

    expect(FreezeMode::isActive())
        ->toBeTrue()
        ->and(DB::table('trading_steps')->count())
        ->toBe(1);
});

it('stays frozen when interactive cleanup is declined', function (): void {
    FreezeMode::activate();

    DB::table('steps')->insert([
        'block_uuid' => Str::uuid()->toString(),
        'state' => 'Pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('kraite:unfreeze')
        ->expectsConfirmation('Protected local data exists. Delete it now?', 'no')
        ->assertFailed();

    expect(FreezeMode::isActive())
        ->toBeTrue()
        ->and(DB::table('steps')->count())
        ->toBe(1);
});

it('cleans and unfreezes when interactive cleanup is approved', function (): void {
    FreezeMode::activate();

    DB::table('trading_steps')->insert([
        'block_uuid' => Str::uuid()->toString(),
        'state' => 'Pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->artisan('kraite:unfreeze')
        ->expectsConfirmation('Protected local data exists. Delete it now?', 'yes')
        ->assertSuccessful();

    expect(FreezeMode::isActive())
        ->toBeFalse()
        ->and(DB::table('trading_steps')->count())
        ->toBe(0);
});

it('fails closed when protected state cannot be verified', function (): void {
    FreezeMode::activate();
    $originalConnection = config('database.default');
    config(['database.default' => 'missing-freeze-test-connection']);

    try {
        $this->artisan('kraite:unfreeze', ['--force' => true])
            ->expectsOutputToContain('protected state could not be verified')
            ->assertFailed();
    } finally {
        config(['database.default' => $originalConnection]);
    }

    expect(FreezeMode::isActive())->toBeTrue();
});

it('force-cleans every protected table and logs before unfreezing', function (): void {
    FreezeMode::activate();

    DB::table('steps')->insert([
        'block_uuid' => Str::uuid()->toString(),
        'state' => 'Pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('trading_steps')->insert([
        'block_uuid' => Str::uuid()->toString(),
        'state' => 'Pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('steps_dispatcher_ticks')->insert([
        'group' => 'alpha',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('trading_steps_dispatcher_ticks')->insert([
        'group' => 'alpha',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('model_logs')->insert([
        'loggable_type' => 'test',
        'loggable_id' => 1,
        'event_type' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $logsPath = config('kraite.freeze.logs_path');
    File::ensureDirectoryExists($logsPath.'/nested');
    File::put($logsPath.'/laravel.log', 'must disappear');
    File::put($logsPath.'/nested/jobs.log', 'must disappear');

    $this->artisan('kraite:unfreeze', ['--force' => true])
        ->expectsOutputToContain('UNFROZEN')
        ->assertSuccessful();

    $protectedTables = [
        'orders',
        'positions',
        'steps',
        'steps_dispatcher_ticks',
        'steps_archive',
        'trading_steps',
        'trading_steps_dispatcher_ticks',
        'trading_steps_archive',
        'api_request_logs',
        'api_snapshots',
        'notification_logs',
        'model_logs',
    ];

    expect(app(FrozenOperationalData::class)->tables())
        ->toBe($protectedTables)
        ->and(FreezeMode::isActive())
        ->toBeFalse()
        ->and(File::exists($logsPath.'/laravel.log'))
        ->toBeFalse()
        ->and(File::isDirectory($logsPath.'/nested'))
        ->toBeFalse();

    foreach ($protectedTables as $table) {
        expect(DB::table($table)->count())->toBe(0, "{$table} was not cleaned");
    }
});

it('unfreezes without a prompt when the protected scope is already clean', function (): void {
    FreezeMode::activate();

    $this->artisan('kraite:unfreeze', ['--no-interaction' => true])
        ->expectsOutputToContain('UNFROZEN')
        ->assertSuccessful();

    expect(FreezeMode::isActive())->toBeFalse();
});
