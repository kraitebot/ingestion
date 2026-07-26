<?php

declare(strict_types=1);

namespace Tests\Support;

use Kraite\Core\Contracts\ProductionDatabaseCloneGateway;
use RuntimeException;

/**
 * In-memory stand-in for the production clone gateway. Records every
 * dump, download, import, and cleanup call instead of touching the
 * remote host, so the clone command can be pinned without ever reaching
 * production.
 *
 * Set `$failImport` to simulate an import that dies midway.
 *
 * Used by `tests/Feature/Commands/CloneCommandTest`.
 */
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
