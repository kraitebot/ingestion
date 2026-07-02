<?php

declare(strict_types=1);

use Kraite\Core\Commands\Daemons\StreamBinanceUserDataCommand;
use Kraite\Core\Support\NotificationMessageBuilder;

/**
 * Pins the fleet-scale hardening of the user-data daemon (2026-07-02).
 *
 * The daemon runs ONE process multiplexing one WebSocket per Binance
 * account, so any restart resets every account at once. Three amplifiers
 * turned that into a storm at 100 accounts, and each is fixed here:
 *
 *   A — a "connected" notification fired per account per (re)connect, so
 *       a restart = N messages. Now a single fleet boot-summary
 *       (binance_user_data_daemon_online) covers boot; per-account connect
 *       is log-only; only failures still page.
 *   B — every account's handshake fired in the same tick (a thundering
 *       herd of N simultaneous connects from one IP, risking Binance's
 *       per-IP WS connection rate limit). Connects are now staggered on a
 *       linear ramp.
 *   C — a FIXED 512MB self-exit ceiling was crossed by ~43 accounts of
 *       NORMAL load (~10MB/account + base), crash-looping the whole daemon
 *       and resetting everyone. The ceiling now scales with account count.
 */

// ---- C: account-aware memory ceiling ----

it('scales the memory ceiling with the live account count', function (): void {
    $cmd = new StreamBinanceUserDataCommand;
    $base = 200 * 1024 * 1024;
    $perAccount = 25 * 1024 * 1024;

    // Empty daemon floors to one account's budget — never below base.
    expect($cmd->memoryLimitBytes())->toBe($base + $perAccount);

    for ($id = 1; $id <= 100; $id++) {
        $cmd->injectSlotForTest($id, ['client' => null, 'listen_key' => 'k'.$id]);
    }

    // 100 accounts → ceiling grows linearly, well past the old fixed
    // 512MB, so normal fleet load never trips the self-exit (the
    // crash-loop-of-mass-resets bug this fix removes).
    expect($cmd->memoryLimitBytes())->toBe($base + ($perAccount * 100));
    expect($cmd->memoryLimitBytes())->toBeGreaterThan(512 * 1024 * 1024);
});

// ---- B: staggered connect ramp ----

it('staggers connects on a linear ramp so N handshakes never fire at once', function (): void {
    $cmd = new StreamBinanceUserDataCommand;

    // Index 0 (a single steady-state respawn) connects immediately.
    expect($cmd->connectStaggerDelay(0))->toBe(0.0);
    // Each subsequent account is spaced 0.25s → 4 connects/sec.
    expect($cmd->connectStaggerDelay(1))->toBe(0.25);
    expect($cmd->connectStaggerDelay(4))->toBe(1.0);
    // 100 accounts ramp over ~25s — comfortably under any connection
    // rate limit while still bringing the fleet online quickly.
    expect($cmd->connectStaggerDelay(99))->toBeLessThanOrEqual(30.0);
});

// ---- A: one boot summary instead of a per-account storm ----

it('renders a single fleet boot-summary notification instead of one per account', function (): void {
    $payload = NotificationMessageBuilder::build('binance_user_data_daemon_online', [
        'account_count' => 100,
        'pid' => 12345,
    ]);

    expect($payload['title'])->toContain('100');
    expect($payload['title'])->toContain('daemon online');
    expect($payload['emailMessage'])->toBeString()->not->toBeEmpty();
});
