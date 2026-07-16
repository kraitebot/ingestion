<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Kraite\Core\Support\Database\SshProductionDatabaseCloneGateway;
use Symfony\Component\Process\Process as SymfonyProcess;

beforeEach(function (): void {
    $this->cloneTestDirectory = storage_path('framework/testing/kraite-clone-gateway-'.Str::uuid());
    $this->identityFile = $this->cloneTestDirectory.'/id_ed25519_kraite';
    $this->localDumpDirectory = $this->cloneTestDirectory.'/local';
    $this->remoteDumpDirectory = '/remote/kraite-clone-tests';

    File::ensureDirectoryExists($this->cloneTestDirectory);
    File::put($this->identityFile, 'test-key-placeholder');

    config([
        'kraite.clone.timeout_seconds' => 1800,
        'kraite.clone.local_dump_directory' => $this->localDumpDirectory,
        'kraite.clone.remote_dump_directory' => $this->remoteDumpDirectory,
        'kraite.clone.production.host' => '203.0.113.20',
        'kraite.clone.production.ssh_user' => 'root',
        'kraite.clone.production.app_user' => 'athena',
        'kraite.clone.production.project_path' => '/home/athena/ingestion.kraite.com',
        'kraite.clone.production.identity_file' => $this->identityFile,
    ]);

    $this->gateway = new SshProductionDatabaseCloneGateway;
});

afterEach(function (): void {
    File::deleteDirectory($this->cloneTestDirectory);
});

it('reads bounded production migration metadata through the configured SSH identity', function (): void {
    $payload = base64_encode(json_encode([
        'database' => 'kraite',
        'count' => 2,
        'names' => ['2026_01_01_first', '2026_01_02_second'],
    ], JSON_THROW_ON_ERROR));

    Process::preventStrayProcesses();
    Process::fake(fn (PendingProcess $process) => Process::result(
        output: str_contains(processCommand($process), 'kraite-clone:migrations')
            ? 'KRAITE_CLONE_JSON:'.$payload
            : '',
    ));

    expect($this->gateway->productionMigrationNames())
        ->toBe(['2026_01_01_first', '2026_01_02_second']);

    Process::assertRan(function (PendingProcess $process): bool {
        $command = processCommand($process);

        return str_contains($command, 'ssh')
            && str_contains($command, $this->identityFile)
            && str_contains($command, 'root@203.0.113.20')
            && str_contains($command, 'kraite-clone:migrations');
    });
});

it('builds a data-only production dump without exposing database credentials', function (): void {
    $payload = base64_encode(json_encode([
        'database' => 'kraite',
        'count' => 1,
        'names' => ['2026_01_01_first'],
    ], JSON_THROW_ON_ERROR));

    Process::preventStrayProcesses();
    Process::fake(fn (PendingProcess $process) => Process::result(
        output: str_contains(processCommand($process), 'kraite-clone:migrations')
            ? 'KRAITE_CLONE_JSON:'.$payload
            : '',
    ));

    $this->gateway->productionMigrationNames();
    $this->gateway->createProductionDump(
        ['exchange_symbols', 'users'],
        $this->remoteDumpDirectory.'/kraite-clone-20260101-test.sql.gz',
    );

    Process::assertRan(function (PendingProcess $process): bool {
        $command = processCommand($process);

        if (! str_contains($command, 'kraite-clone:dump')) {
            return false;
        }

        expect($command)
            ->toContain('--single-transaction')
            ->toContain('--no-create-info')
            ->toContain('--skip-triggers')
            ->toContain('--skip-add-locks')
            ->toContain('exchange_symbols')
            ->toContain('users')
            ->not->toContain('indicator_histories')
            ->not->toContain('DB_PASSWORD');

        $arguments = $process->command;
        expect($arguments)->toBeArray();

        $remoteShell = is_array($arguments) ? end($arguments) : null;
        expect($remoteShell)->toBeString();

        $syntaxCheck = new SymfonyProcess(['bash', '-n', '-c', $remoteShell]);
        $syntaxCheck->run();
        expect($syntaxCheck->getExitCode())->toBe(0, $syntaxCheck->getErrorOutput());

        return true;
    });
});

it('truncates only included local tables and removes its temporary credential file', function (): void {
    $localDump = $this->localDumpDirectory.'/kraite-clone-20260101-test.sql.gz';
    File::ensureDirectoryExists($this->localDumpDirectory);
    File::put($localDump, str_repeat('x', 2048));

    $connectionName = config('database.default');
    config(["database.connections.{$connectionName}.password" => 'super-secret-clone-password']);

    Process::preventStrayProcesses();
    Process::fake();

    $credentialFilesBefore = File::glob(storage_path('framework/kraite-clone-client-*.cnf'));

    $this->gateway->replaceLocalTables(['exchange_symbols', 'users'], $localDump);

    Process::assertRan(function (PendingProcess $process): bool {
        $command = processCommand($process);

        if (! str_contains($command, 'TRUNCATE TABLE')) {
            return false;
        }

        expect($command)
            ->toContain('TRUNCATE TABLE `exchange_symbols`')
            ->toContain('TRUNCATE TABLE `users`')
            ->not->toContain('TRUNCATE TABLE `steps`')
            ->not->toContain('super-secret-clone-password');

        return true;
    });

    expect(File::glob(storage_path('framework/kraite-clone-client-*.cnf')))
        ->toBe($credentialFilesBefore);
});

function processCommand(PendingProcess $process): string
{
    return is_array($process->command)
        ? implode(' ', $process->command)
        : (string) $process->command;
}
