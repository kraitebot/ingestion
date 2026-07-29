<?php

declare(strict_types=1);

use Kraite\Core\Support\Logging\ApiRequestLogRetention;

/**
 * What an exchange call leaves behind in `api_request_logs`.
 *
 * Measured on production 2026-07-29: on a successful call the response body
 * averages 11,554 bytes — 92% of the row — while the rest of the record
 * (method, path, status, timing, hostname) is under 100. At 67k calls a day
 * that built a 3.2 GB table against a 2 GB InnoDB buffer pool, making it the
 * single most expensive object in the database by I/O time: 1,175 seconds
 * against 1,065 for `steps`, on a sixth of the reads.
 *
 * A body earns its keep when something went wrong or something was slow.
 * A fast 200 does not: nobody has ever debugged an incident from the body of
 * a call that succeeded quickly.
 */
it('keeps the body when the call failed', function (int $status): void {
    expect(ApiRequestLogRetention::shouldRetainBody($status, durationMs: 50))->toBeTrue();
})->with([
    'bad request' => 400,
    'unauthorised' => 401,
    'rate limited' => 429,
    'exchange error' => 500,
    'exchange unavailable' => 503,
]);

it('keeps the body when the call was slow, even though it succeeded', function (): void {
    // A 200 that took nine seconds is exactly the forensic case: the call
    // worked, but something about it is worth seeing.
    expect(ApiRequestLogRetention::shouldRetainBody(200, durationMs: 9000))->toBeTrue();
});

it('drops the body on a fast successful call', function (int $status): void {
    expect(ApiRequestLogRetention::shouldRetainBody($status, durationMs: 120))->toBeFalse();
})->with([
    'ok' => 200,
    'created' => 201,
    'no content' => 204,
]);

it('treats an unknown status as worth keeping', function (): void {
    // No status means the call did not complete normally.
    expect(ApiRequestLogRetention::shouldRetainBody(null, durationMs: 10))->toBeTrue();
});

it('honours the configured slow threshold', function (): void {
    config()->set('kraite.api_request_logs.retain_body_above_ms', 500);

    expect(ApiRequestLogRetention::shouldRetainBody(200, durationMs: 499))->toBeFalse()
        ->and(ApiRequestLogRetention::shouldRetainBody(200, durationMs: 500))->toBeTrue();
});

it('can be switched back to keeping everything while debugging', function (): void {
    config()->set('kraite.api_request_logs.retain_all_bodies', true);

    expect(ApiRequestLogRetention::shouldRetainBody(200, durationMs: 1))->toBeTrue();
});

it('never drops the status, timing or path that diagnostics actually rely on', function (): void {
    // Guards the columns the alert runbooks query: they must not appear in
    // the set this class is allowed to clear.
    expect(ApiRequestLogRetention::CLEARABLE_COLUMNS)
        ->not->toContain('http_response_code')
        ->not->toContain('duration')
        ->not->toContain('path')
        ->not->toContain('hostname')
        ->not->toContain('error_message')
        ->toContain('response');
});
