<?php

declare(strict_types=1);

use Kraite\Core\Commands\Daemons\StreamBinanceUserDataCommand;
use Tests\Support\FakeWsClient;

/**
 * Pin the close-then-unset contract on listen-key rotation and expiry.
 *
 * The daemon hosts one Pawl WebSocket per Binance account. Three lifecycle
 * paths tear down a slot:
 *   - reap (account deactivation / api_key removal)
 *   - listen-key rotation (DB key differs from in-memory key)
 *   - listen-key expiry (Binance pushes `e=listenKeyExpired`)
 *
 * Pre-fix, only `reapAccount()` closed the underlying Pawl connection
 * before unsetting the slot. Rotation and expiry just unset the slot,
 * leaving the old connection alive in the ReactPHP loop until Binance
 * server-side close or the idle watchdog acted. With keys rotating every
 * ~60min × N accounts that orphan rate scales linearly with fleet size.
 *
 * Post-fix, all three paths route through `closeAndUnsetSlot()` so the
 * close-then-unset shape is uniform.
 */
it('closeAndUnsetSlot calls close() on the slot client and removes the slot', function (): void {
    $cmd = new StreamBinanceUserDataCommand;
    $client = new FakeWsClient;

    $cmd->injectSlotForTest(42, ['client' => $client, 'listen_key' => 'kAAAAA']);
    expect($cmd->hasSlotForTest(42))->toBeTrue();
    expect($client->closed)->toBeFalse();

    $cmd->closeAndUnsetSlot(42);

    expect($client->closed)->toBeTrue();
    expect($cmd->hasSlotForTest(42))->toBeFalse();
});

it('closeAndUnsetSlot still unsets the slot when the client close() throws', function (): void {
    $cmd = new StreamBinanceUserDataCommand;
    $client = new FakeWsClient;
    $client->closeShouldThrow = true;

    $cmd->injectSlotForTest(99, ['client' => $client, 'listen_key' => 'kAAAAA']);

    $cmd->closeAndUnsetSlot(99);

    // The unset MUST run even if close() threw — otherwise a dead client
    // would block re-init forever.
    expect($cmd->hasSlotForTest(99))->toBeFalse();
});

it('closeAndUnsetSlot is a no-op when the slot does not exist', function (): void {
    $cmd = new StreamBinanceUserDataCommand;

    expect($cmd->hasSlotForTest(123))->toBeFalse();

    // Should not throw when the account has no slot — multiple lifecycle
    // paths can race, the first one wins, the second one no-ops cleanly.
    $cmd->closeAndUnsetSlot(123);

    expect($cmd->hasSlotForTest(123))->toBeFalse();
});

it('closeAndUnsetSlot tolerates a slot whose client lacks a close() method', function (): void {
    $cmd = new StreamBinanceUserDataCommand;

    // Defensive shape — if a slot somehow holds a malformed entry (test
    // fixture, corruption, edge case), the unset must still happen.
    $cmd->injectSlotForTest(7, ['client' => new stdClass, 'listen_key' => 'kAAAAA']);

    $cmd->closeAndUnsetSlot(7);

    expect($cmd->hasSlotForTest(7))->toBeFalse();
});
