<?php

declare(strict_types=1);

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\User;
use Kraite\Core\Support\ApiExceptionHandlers\BinanceExceptionHandler;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class)->group('integration', 'rotation', 'forbidden-ttl');

/*
|--------------------------------------------------------------------------
| ForbiddenHostname 1h TTL Tests
|--------------------------------------------------------------------------
|
| The worker-IP rotation feature treats `forbidden_hostnames` rows as the
| source of truth for "is this IP blacklisted for this (account, api_system)
| pair right now?". For permanent ban types (ip_not_whitelisted,
| account_blocked) the row must auto-expire after 1h so rotation has a
| natural re-probe cadence — without expiry, a single mis-whitelisted IP
| would be locked out forever until manual operator intervention.
|
| Pre-rotation behaviour set forbidden_until=null for these types ("sticky
| until proof of repair"). With rotation in place, the 1h TTL lets the
| system rediscover whether the underlying issue is still present without
| needing the success-path self-heal to fire on a banned IP that can't
| even make a successful call.
|
*/

function buildBinanceUnauthorizedException(int $statusCode, int $vendorCode, string $message): ClientException
{
    $body = '{"code":'.$vendorCode.',"msg":"'.$message.'"}';

    $request = new Request('GET', 'https://fapi.binance.com/fapi/v1/account');
    $response = new Response($statusCode, [], $body);

    return new ClientException("Client error: {$statusCode} {$message}", $request, $response);
}

function makeBinanceHandlerForAccount(): BinanceExceptionHandler
{
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance', 'name' => 'Binance']);
    $user = User::factory()->create();
    $account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);

    $handler = new BinanceExceptionHandler;
    $handler->withAccount($account);

    return $handler;
}

it('marks ip_not_whitelisted rows with a 1h forbidden_until instead of null', function (): void {
    $handler = makeBinanceHandlerForAccount();

    $handler->forbidIpNotWhitelisted(
        buildBinanceUnauthorizedException(401, -2015, 'Invalid API-key, IP, or permissions for action.')
    );

    $row = ForbiddenHostname::query()
        ->where('type', ForbiddenHostname::TYPE_IP_NOT_WHITELISTED)
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->forbidden_until)->not->toBeNull()
        ->and(abs($row->forbidden_until->diffInSeconds(now())))->toBeGreaterThan(3000)
        ->and(abs($row->forbidden_until->diffInSeconds(now())))->toBeLessThan(3900);
});

it('marks account_blocked rows with a 1h forbidden_until instead of null', function (): void {
    $handler = makeBinanceHandlerForAccount();

    $handler->forbidAccountBlocked(
        buildBinanceUnauthorizedException(401, -2008, 'Invalid API-Key.')
    );

    $row = ForbiddenHostname::query()
        ->where('type', ForbiddenHostname::TYPE_ACCOUNT_BLOCKED)
        ->latest('id')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->forbidden_until)->not->toBeNull()
        ->and(abs($row->forbidden_until->diffInSeconds(now())))->toBeGreaterThan(3000)
        ->and(abs($row->forbidden_until->diffInSeconds(now())))->toBeLessThan(3900);
});

it('refreshes forbidden_until in place on re-detection without inserting a duplicate row', function (): void {
    $handler = makeBinanceHandlerForAccount();
    $exception = buildBinanceUnauthorizedException(401, -2015, 'Invalid API-key, IP, or permissions for action.');

    $handler->forbidIpNotWhitelisted($exception);

    $firstRowId = ForbiddenHostname::query()
        ->where('type', ForbiddenHostname::TYPE_IP_NOT_WHITELISTED)
        ->value('id');

    // Move the clock forward 45 minutes and re-trigger the same ban.
    // The existing forbidden_until (≈ +1h from t0 = +15min from now) should
    // be replaced by a fresh +1h from t1 — the row id stays stable.
    $this->travel(45)->minutes();

    $handler->forbidIpNotWhitelisted($exception);

    $allRows = ForbiddenHostname::query()
        ->where('type', ForbiddenHostname::TYPE_IP_NOT_WHITELISTED)
        ->get();

    expect($allRows)->toHaveCount(1)
        ->and($allRows->first()->id)->toBe($firstRowId)
        ->and(abs($allRows->first()->forbidden_until->diffInSeconds(now())))->toBeGreaterThan(3000)
        ->and(abs($allRows->first()->forbidden_until->diffInSeconds(now())))->toBeLessThan(3900);
});
