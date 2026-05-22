<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ForbiddenHostname;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\User;
use Kraite\Core\Support\ApiClients\REST\BinanceApiClient;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Support\ValueObjects\ApiRequest;

/**
 * BaseApiClient self-heal of user-fixable ForbiddenHostname rows.
 *
 * The 2026-05-12 incident on account #1 (Karine) left a permanent
 * (forbidden_until=NULL) ip_not_whitelisted row long after the user
 * actually whitelisted the IP on Binance — every step gated by
 * BaseApiableJob kept retrying until MaxRetries because nothing was
 * deleting the stale row. The fix puts a self-heal hook on the
 * successful-response path so a confirmed working credential stack
 * removes the matching block automatically.
 */
uses(RefreshDatabase::class)->group('unit', 'api-client', 'forbidden-hostname', 'self-heal');

beforeEach(function (): void {
    $this->apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
        'recvwindow_margin' => 10000,
    ]);

    $user = User::factory()->create();

    $this->account = Account::factory()->create([
        'user_id' => $user->id,
        'api_system_id' => $this->apiSystem->id,
    ]);

    $this->client = new BinanceApiClient([
        'url' => 'https://fapi.binance.test',
        'api_key' => 'TESTKEY',
        'api_secret' => 'TESTSECRET',
    ]);
});

it('deletes ip_not_whitelisted row for the same account+ip+exchange after a successful call', function (): void {
    $row = ForbiddenHostname::create([
        'api_system_id' => $this->apiSystem->id,
        'account_id' => $this->account->id,
        'ip_address' => Kraite::ip(),
        'type' => ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
        'forbidden_until' => null,
    ]);

    Http::fake([
        '*' => Http::response([], 200),
    ]);

    $properties = new ApiProperties;
    $properties->set('account', $this->account);
    $properties->set('options.symbol', 'BTCUSDT');

    $this->client->signRequest(ApiRequest::make('GET', '/fapi/v1/openOrders', $properties));

    expect(ForbiddenHostname::find($row->id))->toBeNull();
});

it('deletes account_blocked row for the same account+ip+exchange after a successful call', function (): void {
    $row = ForbiddenHostname::create([
        'api_system_id' => $this->apiSystem->id,
        'account_id' => $this->account->id,
        'ip_address' => Kraite::ip(),
        'type' => ForbiddenHostname::TYPE_ACCOUNT_BLOCKED,
        'forbidden_until' => null,
    ]);

    Http::fake([
        '*' => Http::response([], 200),
    ]);

    $properties = new ApiProperties;
    $properties->set('account', $this->account);
    $properties->set('options.symbol', 'BTCUSDT');

    $this->client->signRequest(ApiRequest::make('GET', '/fapi/v1/openOrders', $properties));

    expect(ForbiddenHostname::find($row->id))->toBeNull();
});

it('does NOT delete ip_rate_limited rows (those auto-recover via forbidden_until)', function (): void {
    $row = ForbiddenHostname::create([
        'api_system_id' => $this->apiSystem->id,
        'account_id' => $this->account->id,
        'ip_address' => Kraite::ip(),
        'type' => ForbiddenHostname::TYPE_IP_RATE_LIMITED,
        'forbidden_until' => now()->addMinutes(30),
    ]);

    Http::fake([
        '*' => Http::response([], 200),
    ]);

    $properties = new ApiProperties;
    $properties->set('account', $this->account);
    $properties->set('options.symbol', 'BTCUSDT');

    $this->client->signRequest(ApiRequest::make('GET', '/fapi/v1/openOrders', $properties));

    expect(ForbiddenHostname::find($row->id))->not->toBeNull();
});

it('does NOT delete ip_banned rows (system-wide; a single account success cannot vouch for global state)', function (): void {
    $row = ForbiddenHostname::create([
        'api_system_id' => $this->apiSystem->id,
        'account_id' => null,
        'ip_address' => Kraite::ip(),
        'type' => ForbiddenHostname::TYPE_IP_BANNED,
        'forbidden_until' => null,
    ]);

    Http::fake([
        '*' => Http::response([], 200),
    ]);

    $properties = new ApiProperties;
    $properties->set('account', $this->account);
    $properties->set('options.symbol', 'BTCUSDT');

    $this->client->signRequest(ApiRequest::make('GET', '/fapi/v1/openOrders', $properties));

    expect(ForbiddenHostname::find($row->id))->not->toBeNull();
});

it('does NOT delete rows belonging to other accounts on the same IP+exchange', function (): void {
    $otherUser = User::factory()->create();
    $otherAccount = Account::factory()->create([
        'user_id' => $otherUser->id,
        'api_system_id' => $this->apiSystem->id,
    ]);

    $row = ForbiddenHostname::create([
        'api_system_id' => $this->apiSystem->id,
        'account_id' => $otherAccount->id,
        'ip_address' => Kraite::ip(),
        'type' => ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
        'forbidden_until' => null,
    ]);

    Http::fake([
        '*' => Http::response([], 200),
    ]);

    $properties = new ApiProperties;
    $properties->set('account', $this->account);
    $properties->set('options.symbol', 'BTCUSDT');

    $this->client->signRequest(ApiRequest::make('GET', '/fapi/v1/openOrders', $properties));

    expect(ForbiddenHostname::find($row->id))->not->toBeNull();
});

it('does NOT delete rows for a different exchange on the same account+ip', function (): void {
    $otherApiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'bybit',
        'name' => 'Bybit',
    ]);

    $row = ForbiddenHostname::create([
        'api_system_id' => $otherApiSystem->id,
        'account_id' => $this->account->id,
        'ip_address' => Kraite::ip(),
        'type' => ForbiddenHostname::TYPE_IP_NOT_WHITELISTED,
        'forbidden_until' => null,
    ]);

    Http::fake([
        '*' => Http::response([], 200),
    ]);

    $properties = new ApiProperties;
    $properties->set('account', $this->account);
    $properties->set('options.symbol', 'BTCUSDT');

    $this->client->signRequest(ApiRequest::make('GET', '/fapi/v1/openOrders', $properties));

    expect(ForbiddenHostname::find($row->id))->not->toBeNull();
});
