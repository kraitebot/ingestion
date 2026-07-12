<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * The Haiku narrator (kraite:monitor-narrate) is documentation-only. It
 * must: no-op when there is no open incident; leave the deterministic
 * stub untouched when the CLI is unavailable/failing (money protection
 * never depends on it); and enrich + flag-narrated when the model returns
 * text. It NEVER cools, never touches trading.
 */
uses()->group('feature', 'monitor', 'guard');

function monitoringDir(): string
{
    return base_path('monitoring');
}

function writeStubIncident(string $stamp = '20260712_120000'): string
{
    File::ensureDirectoryExists(monitoringDir());
    $file = monitoringDir().'/'.$stamp.'.md';
    File::put($file, "# incident {$stamp}\n\n- trigger: **failed_positions_burst**\n- narrated: NO\n\n## Narration (Haiku fills this in)\n\n_pending_\n");
    File::put(monitoringDir().'/OPEN-INCIDENT', $stamp.'.md');

    return $file;
}

function cleanMonitoring(): void
{
    foreach (glob(monitoringDir().'/*') ?: [] as $f) {
        @unlink($f);
    }
}

afterEach(function (): void {
    cleanMonitoring();
});

it('no-ops cleanly when there is no open incident', function (): void {
    cleanMonitoring();

    $this->artisan('kraite:monitor-narrate')->assertExitCode(0);
});

it('leaves the deterministic stub untouched when the narrator command fails', function (): void {
    $file = writeStubIncident('20260712_130000');
    // Point the narrator at a command that exits non-zero.
    config(['kraite.guard.narrator_argv' => ['false']]);

    $this->artisan('kraite:monitor-narrate')->assertExitCode(0);

    // Stub preserved, still flagged un-narrated for the next attempt.
    expect(File::get($file))->toContain('narrated: NO')->toContain('_pending_');
});

it('enriches the incident and flags it narrated when the model returns text', function (): void {
    $file = writeStubIncident('20260712_140000');
    // Deterministic fake "model" — echoes a fixed narrative.
    config(['kraite.guard.narrator_argv' => ['printf', '### What happened\nThe bot cooled on a failed-position burst.\n']]);

    $this->artisan('kraite:monitor-narrate')->assertExitCode(0);

    $out = File::get($file);
    expect($out)->toContain('narrated: YES')
        ->toContain('The bot cooled on a failed-position burst.')
        ->not->toContain('_pending_');
});

it('does not re-narrate an already-narrated incident', function (): void {
    $stamp = '20260712_150000';
    File::ensureDirectoryExists(monitoringDir());
    $file = monitoringDir().'/'.$stamp.'.md';
    File::put($file, "# incident\n- narrated: YES\n\n## Narration (Haiku)\n\ndone.\n");
    File::put(monitoringDir().'/OPEN-INCIDENT', $stamp.'.md');
    // A command that would fail loudly if invoked.
    config(['kraite.guard.narrator_argv' => ['false']]);

    $this->artisan('kraite:monitor-narrate')->assertExitCode(0);

    expect(File::get($file))->toBe("# incident\n- narrated: YES\n\n## Narration (Haiku)\n\ndone.\n");
});
