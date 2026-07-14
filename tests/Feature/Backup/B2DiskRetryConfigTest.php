<?php

declare(strict_types=1);

use Aws\S3\S3Client;
use Illuminate\Support\Facades\Storage;

/**
 * Pin the AWS SDK retry config on the `b2` disk used by Spatie's
 * backup destination.
 *
 * Production trigger (2026-05-05, 2026-05-08): backup uploads to
 * Backblaze B2 fail with `InternalError (server): internal incident`
 * on a single multipart part (e.g. Part 195 of 200). The default AWS
 * SDK retry policy ('legacy', 3 attempts) was insufficient against
 * B2's sporadic per-part 500s — one failed part aborts the whole
 * multipart upload and the 1.1 GB transfer is wasted.
 *
 * Contract: the `b2` disk MUST declare an explicit `retries` config
 * with `mode='adaptive'` (standard exponential backoff plus
 * client-side rate limiting) and `max_attempts` strictly greater
 * than the SDK default (3). A future config edit dropping either
 * key would silently re-expose production backups to the same
 * transient B2 failures, so this test pins both.
 */
it('declares adaptive retries with >3 max attempts on the b2 disk config', function (): void {
    $retries = config('filesystems.disks.b2.retries');

    expect($retries)
        ->toBeArray('b2 disk must declare an explicit `retries` config so the AWS '
            .'SDK does not fall back to the 3-attempt legacy default.')
        ->and($retries['mode'] ?? null)
        ->toBe('adaptive', 'b2 disk must use adaptive retry mode — standard '
            .'exponential backoff plus client-side rate limiting against B2 '
            .'transient 500s.')
        ->and($retries['max_attempts'] ?? null)
        ->toBeGreaterThan(3, 'b2 disk max_attempts must exceed the SDK default '
            .'of 3 so a single multipart part hitting a transient B2 server '
            .'error does not abort the entire upload.');
});

/**
 * Smoke pin: the filesystems config still resolves into a working
 * S3Client when the `retries` key is present. Catches the case
 * where a future SDK upgrade changes the accepted shape of the
 * `retries` array and breaks the disk at boot.
 */
it('boots the b2 disk into a real S3Client with the retry config attached', function (): void {
    // Inject fake credentials so the boot check is decoupled from the
    // host's .env.testing. The SDK only validates config shape here —
    // no network call is made — so any non-empty values exercise the
    // same code path as production credentials would.
    config()->set('filesystems.disks.b2.key', 'fake-key-id');
    config()->set('filesystems.disks.b2.secret', 'fake-application-key');
    config()->set('filesystems.disks.b2.region', 'eu-central-003');
    config()->set('filesystems.disks.b2.bucket', 'fake-bucket');
    config()->set('filesystems.disks.b2.endpoint', 'https://s3.eu-central-003.backblazeb2.com');

    // Flush any previously-resolved disk so the new config takes effect.
    Storage::forgetDisk('b2');

    $client = Storage::disk('b2')->getClient();

    expect($client)->toBeInstanceOf(S3Client::class);
});

/**
 * Pin the package-level retry that reruns the complete backup command after a
 * multipart upload still fails despite the B2 disk's per-request retries.
 */
it('retries the complete backup after a terminal destination failure', function (): void {
    expect(config('backup.backup.tries'))
        ->toBe(2, 'A single terminal B2 multipart failure must receive one complete backup retry.')
        ->and(config('backup.backup.retry_delay'))
        ->toBe(60, 'The complete retry must wait briefly before rebuilding and uploading the archive.');
});
