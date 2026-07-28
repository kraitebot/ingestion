<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kraite\Core\Commands\Cronjobs\CheckDriftsCommand;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;

/**
 * Pin the exchange_error_storm signal on the monitor guard.
 *
 * 2026-07-27/28: both storm trips (14:40 and 22:55) were manufactured
 * entirely by our own already-flat close rejections — Binance's
 * "-2022 ReduceOnly Order is rejected" answered to redundant close
 * orders. Zero genuine exchange degradation, two false alarms, each
 * parking new openings until a human cleared the latch. The storm count
 * must ignore the benign already-flat rejection family; every other
 * exchange failure still counts.
 */
function seedGuardApiLog(int $apiSystemId, ?int $accountId, int $code, ?string $errorMessage, ?string $response): void
{
    DB::table('api_request_logs')->insert([
        'api_system_id' => $apiSystemId,
        'account_id' => $accountId,
        'http_response_code' => $code,
        'error_message' => $errorMessage,
        'response' => $response,
        'path' => '/test/guard',
        'http_method' => 'POST',
        'hostname' => 'kraite',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('does not count already-flat close rejections toward the exchange error storm', function (): void {
    $exchange = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $account = Account::factory()->create(['api_system_id' => $exchange->id]);
    $since = Carbon::now()->subMinutes(20);

    // 16 self-inflicted -2022 rejections — the exact 2026-07-27 storm shape.
    foreach (range(1, 16) as $i) {
        seedGuardApiLog($exchange->id, $account->id, 400, null, '{"code":-2022,"msg":"ReduceOnly Order is rejected."}');
    }

    // Bitget/UTA already-flat family arrives as vendor errors inside HTTP 200.
    seedGuardApiLog($exchange->id, $account->id, 200, 'Close failed (code 22002): No position to close', null);
    seedGuardApiLog($exchange->id, $account->id, 200, 'Close failed (code 25227): No position available to close', null);

    $count = app(CheckDriftsCommand::class)->countRecentExchangeApiErrors($since);

    expect($count)->toBe(0, 'Self-inflicted already-flat rejections must never feed the storm counter.');
});

it('still counts genuine exchange failures', function (): void {
    $exchange = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $account = Account::factory()->create(['api_system_id' => $exchange->id]);
    $since = Carbon::now()->subMinutes(20);

    seedGuardApiLog($exchange->id, $account->id, 500, null, '{"code":-1000,"msg":"Internal error"}');
    seedGuardApiLog($exchange->id, $account->id, 503, null, 'Service unavailable');
    seedGuardApiLog($exchange->id, $account->id, 200, 'Vendor failure: order state unknown', null);
    // A benign already-flat row mixed in must not mask the real ones.
    seedGuardApiLog($exchange->id, $account->id, 400, null, '{"code":-2022,"msg":"ReduceOnly Order is rejected."}');

    expect(app(CheckDriftsCommand::class)->countRecentExchangeApiErrors($since))->toBe(3);
});

it('never counts non-exchange system failures', function (): void {
    $taapi = ApiSystem::factory()->create(['canonical' => 'taapi', 'name' => 'Taapi', 'is_exchange' => false]);
    $since = Carbon::now()->subMinutes(20);

    seedGuardApiLog($taapi->id, null, 500, 'boom', null);

    expect(app(CheckDriftsCommand::class)->countRecentExchangeApiErrors($since))->toBe(0);
});
