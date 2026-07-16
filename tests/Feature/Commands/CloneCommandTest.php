<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Kraite\Core\Contracts\ProductionDatabaseCloneGateway;
use Kraite\Core\Support\FreezeMode;

final class FakeProductionDatabaseCloneGateway implements ProductionDatabaseCloneGateway
{
    /** @var list<string> */
    public array $remoteMigrations = [];

    /** @var list<string> */
    public array $remoteTables = [];

    /** @var list<string> */
    public array $dumpedTables = [];

    /** @var list<string> */
    public array $importedTables = [];

    public bool $downloaded = false;

    public bool $remoteCleaned = false;

    public bool $localCleaned = false;

    public bool $migrationProbeRan = false;

    public bool $failImport = false;

    public function productionMigrationNames(): array
    {
        $this->migrationProbeRan = true;

        return $this->remoteMigrations;
    }

    public function productionTableNames(): array
    {
        return $this->remoteTables;
    }

    public function createProductionDump(array $tables, string $remotePath): void
    {
        $this->dumpedTables = $tables;
    }

    public function downloadDump(string $remotePath, string $localPath): void
    {
        $this->downloaded = true;
    }

    public function replaceLocalTables(array $tables, string $localPath): void
    {
        $this->importedTables = $tables;

        if ($this->failImport) {
            throw new RuntimeException('simulated import failure');
        }
    }

    public function deleteProductionDump(string $remotePath): void
    {
        $this->remoteCleaned = true;
    }

    public function deleteLocalDump(string $localPath): void
    {
        $this->localCleaned = true;
    }
}

beforeEach(function (): void {
    config([
        'kraite.freeze.marker_path' => storage_path('framework/testing/kraite-clone-frozen-'.Str::uuid()),
        'kraite.clone.remote_dump_directory' => '/remote/clone-tests',
        'kraite.clone.local_dump_directory' => storage_path('framework/testing/clone-tests'),
    ]);

    $this->cloneGateway = new FakeProductionDatabaseCloneGateway;
    $this->cloneGateway->remoteMigrations = DB::table('migrations')
        ->orderBy('migration')
        ->pluck('migration')
        ->all();
    $this->cloneGateway->remoteTables = [
        'users',
        'exchange_symbols',
        'indicator_histories',
        'api_request_logs',
        'steps_archive',
        'steps',
        'candles',
        'model_logs',
        'trading_steps_archive',
        'steps_dispatcher_saturation',
        'trading_steps',
    ];

    app()->instance(ProductionDatabaseCloneGateway::class, $this->cloneGateway);
});

afterEach(function (): void {
    File::delete(FreezeMode::markerPath());
});

it('refuses to inspect production unless local Kraite is frozen', function (): void {
    expect(FreezeMode::isActive())->toBeFalse();

    $this->artisan('kraite:clone')
        ->expectsOutputToContain('refused')
        ->assertFailed();

    expect($this->cloneGateway->migrationProbeRan)->toBeFalse();
});

it('aborts before dumping when local and production migrations differ', function (): void {
    FreezeMode::activate();
    $this->cloneGateway->remoteMigrations[] = '2099_01_01_000000_future_production_migration';

    $this->artisan('kraite:clone')
        ->expectsOutputToContain('Migration mismatch')
        ->expectsOutputToContain('2099_01_01_000000_future_production_migration')
        ->assertFailed();

    expect($this->cloneGateway->dumpedTables)
        ->toBe([])
        ->and($this->cloneGateway->downloaded)
        ->toBeFalse()
        ->and(FreezeMode::isActive())
        ->toBeTrue();
});

it('replaces every production table except the nine preserved local tables', function (): void {
    FreezeMode::activate();

    $this->artisan('kraite:clone')
        ->expectsOutputToContain('Clone complete')
        ->assertSuccessful();

    expect($this->cloneGateway->dumpedTables)
        ->toBe(['exchange_symbols', 'users'])
        ->and($this->cloneGateway->importedTables)
        ->toBe(['exchange_symbols', 'users'])
        ->and($this->cloneGateway->downloaded)
        ->toBeTrue()
        ->and($this->cloneGateway->remoteCleaned)
        ->toBeTrue()
        ->and($this->cloneGateway->localCleaned)
        ->toBeTrue()
        ->and(FreezeMode::isActive())
        ->toBeTrue();
});

it('cleans temporary dumps and stays frozen when import fails midway', function (): void {
    FreezeMode::activate();
    $this->cloneGateway->failImport = true;

    $this->artisan('kraite:clone')
        ->expectsOutputToContain('partially updated')
        ->assertFailed();

    expect($this->cloneGateway->remoteCleaned)
        ->toBeTrue()
        ->and($this->cloneGateway->localCleaned)
        ->toBeTrue()
        ->and(FreezeMode::isActive())
        ->toBeTrue();
});
