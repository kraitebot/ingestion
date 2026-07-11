<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * ZeptoMail webhook replay/staleness hardening (core 1.64.x, review-diff
 * 03-Medium). The signed request's HMAC covers only the payload, so
 * without a timestamp-age gate and a request_id dedup a captured event
 * could be replayed forever to clobber newer delivery state. These tests
 * pin: fresh signed bounce applies once, a replay of the same request_id
 * is deduped (200, no re-apply), and a stale timestamp is rejected (401).
 */
uses(RefreshDatabase::class)->group('feature', 'webhooks');

const ZEPTO_SECRET = 'test-zeptomail-secret';

function seedBounceableLog(string $messageId): int
{
    return DB::table('notification_logs')->insertGetId([
        'uuid' => (string) Illuminate\Support\Str::uuid(),
        'canonical' => 'test',
        'channel' => 'mail',
        'recipient' => 'user@example.com',
        'message_id' => $messageId,
        'status' => 'delivered',
        'sent_at' => now()->subMinutes(5),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function zeptoPayload(string $requestId): array
{
    return [
        'event_name' => ['hardbounce'],
        'event_message' => [[
            'request_id' => $requestId,
            'event_data' => [[
                'details' => [
                    'reason' => 'Mailbox does not exist',
                    'bounced_recipient' => 'user@example.com',
                    'time' => now()->toIso8601String(),
                ],
            ]],
        ]],
    ];
}

function postZepto(array $payload, int $tsMillis): Illuminate\Testing\TestResponse
{
    $body = json_encode($payload);
    $signature = base64_encode(hash_hmac('sha256', $body, ZEPTO_SECRET, true));
    $header = "ts={$tsMillis};s=".urlencode($signature).';s-algorithm=HmacSHA256';

    return test()->call(
        'POST',
        '/api/webhooks/zeptomail/events',
        [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_PRODUCER_SIGNATURE' => $header],
        $body,
    );
}

beforeEach(function (): void {
    config(['kraite.api.webhooks.zeptomail_secret' => ZEPTO_SECRET]);
});

it('applies a fresh signed bounce exactly once', function (): void {
    $logId = seedBounceableLog('req-fresh-1');

    postZepto(zeptoPayload('req-fresh-1'), now()->getTimestamp() * 1000)
        ->assertOk();

    expect(DB::table('notification_logs')->where('id', $logId)->value('status'))
        ->toBe('hard bounced');
});

it('dedupes a replayed event by request_id without re-applying', function (): void {
    $logId = seedBounceableLog('req-replay-1');
    $ts = now()->getTimestamp() * 1000;

    postZepto(zeptoPayload('req-replay-1'), $ts)->assertOk();

    // Owner "recovers" the log to delivered; a replay must NOT clobber it.
    DB::table('notification_logs')->where('id', $logId)->update(['status' => 'delivered']);

    $replay = postZepto(zeptoPayload('req-replay-1'), $ts)->assertOk();

    expect($replay->json('deduped'))->toBeTrue()
        ->and(DB::table('notification_logs')->where('id', $logId)->value('status'))
        ->toBe('delivered');
});

it('rejects a stale signature timestamp', function (): void {
    seedBounceableLog('req-stale-1');

    postZepto(zeptoPayload('req-stale-1'), now()->subHour()->getTimestamp() * 1000)
        ->assertStatus(401);
});

it('rejects a tampered signature', function (): void {
    seedBounceableLog('req-tamper-1');
    $body = json_encode(zeptoPayload('req-tamper-1'));
    $header = 'ts='.(now()->getTimestamp() * 1000).';s='.urlencode('deadbeef').';s-algorithm=HmacSHA256';

    test()->call(
        'POST', '/api/webhooks/zeptomail/events', [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_PRODUCER_SIGNATURE' => $header], $body,
    )->assertStatus(401);
});
