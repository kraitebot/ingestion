<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Kraite\Core\Jobs\Atomic\Account\TestServerConnectivityStep;
use Kraite\Core\Jobs\Lifecycles\Account\TestExchangeConnectivityStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Notification;
use Kraite\Core\Models\Server;
use Kraite\Core\Models\User;
use Kraite\Core\Notifications\AlertNotification;
use Kraite\Core\Support\Connectivity\AccountServerConnectivityService;
use StepDispatcher\Models\Step;
use StepDispatcher\States\Completed;
use StepDispatcher\States\Failed;

uses(RefreshDatabase::class)->group('feature', 'connectivity');

function createConnectivityServer(array $overrides = []): Server
{
    $id = DB::table('servers')->insertGetId(array_merge([
        'hostname' => 'apollo',
        'ip_address' => '203.0.113.10',
        'is_apiable' => true,
        'needs_whitelisting' => true,
        'own_queue_name' => 'default',
        'description' => 'Worker',
        'type' => 'worker',
        'secret' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return Server::findOrFail($id);
}

function createConnectivityAccount(string $exchange = 'binance'): Account
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $exchange,
        'name' => ucfirst($exchange),
    ]);

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'user_id' => User::factory()->create()->id,
    ]);
}

/**
 * @return array<string, mixed>
 */
function runConnectivityChildStep(Account $account, Server $server): array
{
    $job = new TestServerConnectivityStep($account->id, $server->id);

    $compute = Closure::bind(function (): array {
        return $this->compute();
    }, $job, $job::class);

    return $compute();
}

function runConnectivityParentStep(Step $parent, Account $account): void
{
    $job = new TestExchangeConnectivityStep($account->id);
    $job->step = $parent;

    $compute = Closure::bind(function () {
        return $this->compute();
    }, $job, $job::class);

    $compute();
}

it('starts an account connectivity workflow with immediate server rows', function (): void {
    $account = createConnectivityAccount();
    createConnectivityServer(['hostname' => 'apollo', 'ip_address' => '203.0.113.10']);
    createConnectivityServer(['hostname' => 'ares', 'ip_address' => '203.0.113.11']);

    $payload = app(AccountServerConnectivityService::class)->start($account);

    expect($payload['block_uuid'])->toBeString()
        ->and($payload['total_servers'])->toBe(2)
        ->and($payload['servers'])->toHaveCount(2)
        ->and($payload['servers'][0]['status'])->toBe('testing');

    $this->assertDatabaseHas('steps', [
        'block_uuid' => $payload['block_uuid'],
        'class' => TestExchangeConnectivityStep::class,
        'relatable_type' => Account::class,
        'relatable_id' => $account->id,
    ]);
});

it('fans out child checks only to API execution servers that need whitelisting', function (): void {
    config(['step-dispatcher.queues.valid' => ['apollo', 'ares']]);

    $account = createConnectivityAccount();
    $apollo = createConnectivityServer(['hostname' => 'apollo', 'ip_address' => '203.0.113.10', 'own_queue_name' => 'apollo']);
    $ares = createConnectivityServer(['hostname' => 'ares', 'ip_address' => '203.0.113.11', 'own_queue_name' => 'ares']);
    createConnectivityServer(['hostname' => 'zeus', 'type' => 'database', 'is_apiable' => false, 'needs_whitelisting' => false]);
    createConnectivityServer(['hostname' => 'artemis', 'type' => 'indicators', 'is_apiable' => true, 'needs_whitelisting' => true]);
    createConnectivityServer(['hostname' => 'helios', 'type' => 'web', 'is_apiable' => true, 'needs_whitelisting' => false]);

    $payload = app(AccountServerConnectivityService::class)->start($account);

    $parent = Step::where('block_uuid', $payload['block_uuid'])->firstOrFail();
    runConnectivityParentStep($parent, $account);

    $children = Step::where('block_uuid', $parent->child_block_uuid)->orderBy('id')->get();

    expect($children)->toHaveCount(2)
        ->and($children->pluck('class')->unique()->values()->all())->toBe([TestServerConnectivityStep::class])
        ->and($children->pluck('queue')->sort()->values()->all())->toBe(['apollo', 'ares'])
        ->and($children->pluck('arguments.serverId')->sort()->values()->all())->toBe([$apollo->id, $ares->id]);
});

it('rolls back every connectivity child and rebuilds the full fan-out after a partial creation failure', function (): void {
    $account = createConnectivityAccount();
    $apollo = createConnectivityServer([
        'hostname' => 'atomic-connectivity-apollo',
        'ip_address' => '203.0.113.20',
    ]);
    $ares = createConnectivityServer([
        'hostname' => 'atomic-connectivity-ares',
        'ip_address' => '203.0.113.21',
    ]);
    $unrelatedAccount = Account::factory()->create([
        'api_system_id' => $account->api_system_id,
        'user_id' => User::factory()->create()->id,
    ]);
    $unrelatedChild = Step::create([
        'class' => TestServerConnectivityStep::class,
        'queue' => 'default',
        'relatable_type' => Account::class,
        'relatable_id' => $unrelatedAccount->id,
        'arguments' => [
            'accountId' => $unrelatedAccount->id,
            'serverId' => $apollo->id,
        ],
        'block_uuid' => '11111111-1111-4111-8111-111111111111',
        'index' => 1,
    ]);

    $payload = app(AccountServerConnectivityService::class)->start($account);
    $parent = Step::query()->where('block_uuid', $payload['block_uuid'])->sole();

    $childrenForAccount = fn () => Step::query()
        ->where('class', TestServerConnectivityStep::class)
        ->where('relatable_type', Account::class)
        ->where('relatable_id', $account->id)
        ->get();

    expect($parent->child_block_uuid)->toBeNull()
        ->and($childrenForAccount())->toHaveCount(0)
        ->and($unrelatedChild->fresh())->not->toBeNull();

    $failAresCreation = true;
    Step::creating(function (Step $step) use ($ares, &$failAresCreation): void {
        if ($failAresCreation
            && $step->class === TestServerConnectivityStep::class
            && ($step->arguments['serverId'] ?? null) === $ares->id) {
            throw new RuntimeException('Simulated connectivity child creation failure.');
        }
    });

    try {
        expect(fn () => runConnectivityParentStep($parent->fresh(), $account))
            ->toThrow(RuntimeException::class, 'Simulated connectivity child creation failure.');
    } finally {
        $failAresCreation = false;
    }

    expect($parent->fresh()->child_block_uuid)->toBeNull()
        ->and($childrenForAccount())->toHaveCount(0)
        ->and($unrelatedChild->fresh())->not->toBeNull();

    runConnectivityParentStep($parent->fresh(), $account);

    $children = $childrenForAccount();

    expect($parent->fresh()->child_block_uuid)->not->toBeNull()
        ->and($children)->toHaveCount(2)
        ->and($children->pluck('arguments.serverId')->sort()->values()->all())->toBe([$apollo->id, $ares->id])
        ->and($children->pluck('priority')->unique()->all())->toBe(['high'])
        ->and($unrelatedChild->fresh())->not->toBeNull();
});

it('maps per-server step states into console connectivity statuses', function (): void {
    $account = createConnectivityAccount();
    $apollo = createConnectivityServer(['hostname' => 'apollo', 'ip_address' => '203.0.113.10']);
    $ares = createConnectivityServer(['hostname' => 'ares', 'ip_address' => '203.0.113.11']);

    $payload = app(AccountServerConnectivityService::class)->start($account);

    $parent = Step::where('block_uuid', $payload['block_uuid'])->firstOrFail();
    runConnectivityParentStep($parent, $account);

    $children = Step::where('block_uuid', $parent->child_block_uuid)->get()->keyBy(fn (Step $step): int => (int) $step->arguments['serverId']);
    $children[$apollo->id]->update(['state' => Completed::class, 'response' => ['result' => 'ok']]);
    $children[$ares->id]->update(['state' => Failed::class, 'error_message' => 'Invalid API-key, IP, or permissions for action.']);

    $status = app(AccountServerConnectivityService::class)->status($payload['block_uuid']);
    $rows = collect($status['servers'])->keyBy('hostname');

    expect($status['is_complete'])->toBeTrue()
        ->and($rows['apollo']['status'])->toBe('connected')
        ->and($rows['apollo']['can_notify_user'])->toBeFalse()
        ->and($rows['ares']['status'])->toBe('not_connected')
        ->and($rows['ares']['can_notify_user'])->toBeTrue();
});

it('checks Binance wallet permissions and reports withdrawals as disabled', function (): void {
    $account = createConnectivityAccount();
    $account->forceFill([
        'binance_api_key' => 'connectivity-binance-key',
        'binance_api_secret' => 'connectivity-binance-secret',
    ])->save();
    $server = createConnectivityServer();

    Http::fake(function (HttpRequest $request) {
        return match (true) {
            str_contains($request->url(), '/fapi/v3/balance') => Http::response([], 200),
            str_contains($request->url(), '/fapi/v1/openOrders') => Http::response([], 200),
            str_contains($request->url(), '/sapi/v1/account/apiRestrictions') => Http::response([
                'enableWithdrawals' => false,
            ], 200),
            default => Http::response(['message' => 'Unexpected test URL'], 500),
        };
    });

    $result = runConnectivityChildStep($account, $server);

    expect($result['result'])->toBe('ok')
        ->and($result['balance_checked'])->toBeTrue()
        ->and($result['open_orders_count'])->toBe(0)
        ->and($result['withdrawals_enabled'])->toBeFalse();

    Http::assertSent(fn (HttpRequest $request): bool => str_starts_with($request->url(), 'https://api.binance.com/')
        && str_contains($request->url(), '/sapi/v1/account/apiRestrictions'));
});

it('checks Bitget account authorities and reports withdrawal access as enabled', function (): void {
    $account = createConnectivityAccount('bitget');
    $account->forceFill([
        'bitget_api_key' => 'connectivity-bitget-key',
        'bitget_api_secret' => 'connectivity-bitget-secret',
        'bitget_passphrase' => 'connectivity-bitget-passphrase',
    ])->save();
    $server = createConnectivityServer();

    Http::fake(function (HttpRequest $request) {
        return match (true) {
            str_contains($request->url(), '/api/v2/mix/account/accounts') => Http::response([
                'code' => '00000',
                'data' => [],
            ], 200),
            str_contains($request->url(), '/api/v2/mix/order/orders-pending') => Http::response([
                'code' => '00000',
                'data' => ['entrustedList' => []],
            ], 200),
            str_contains($request->url(), '/api/v2/spot/account/info') => Http::response([
                'code' => '00000',
                'data' => ['authorities' => ['coow', 'cpow', 'wwow']],
            ], 200),
            default => Http::response(['message' => 'Unexpected test URL'], 500),
        };
    });

    $result = runConnectivityChildStep($account, $server);

    expect($result['result'])->toBe('ok')
        ->and($result['balance_checked'])->toBeTrue()
        ->and($result['open_orders_count'])->toBe(0)
        ->and($result['withdrawals_enabled'])->toBeTrue();

    Http::assertSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/api/v2/spot/account/info'));
});

it('sends the existing whitelist notification for a failed server result', function (): void {
    config(['kraite.notifications_enabled' => true]);
    NotificationFacade::fake();

    Notification::factory()->serverIpNotWhitelisted()->create();

    $account = createConnectivityAccount();
    $server = createConnectivityServer([
        'hostname' => 'apollo',
        'ip_address' => '203.0.113.10',
    ]);

    $sent = app(AccountServerConnectivityService::class)->notify($account, $server);

    expect($sent)->toBeTrue();

    NotificationFacade::assertSentTo(
        $account->user,
        AlertNotification::class,
        fn (AlertNotification $notification): bool => $notification->canonical === 'server_ip_not_whitelisted'
            && str_contains($notification->message, '203.0.113.10')
    );
});

it('probes a unified Bitget account end-to-end over the v3 API', function (): void {
    $account = createConnectivityAccount('bitget');
    $account->forceFill([
        'bitget_api_key' => 'uta-probe-key',
        'bitget_api_secret' => 'uta-probe-secret',
        'bitget_passphrase' => 'uta-probe-passphrase',
        'bitget_account_mode' => 'unified',
    ])->save();
    $server = createConnectivityServer();

    Http::fake(function (HttpRequest $request) {
        return match (true) {
            str_contains($request->url(), '/api/v3/account/assets') => Http::response([
                'code' => '00000',
                'data' => ['accountEquity' => '10', 'assets' => [['coin' => 'USDT', 'equity' => '10', 'available' => '10']]],
            ], 200),
            str_contains($request->url(), '/api/v3/trade/unfilled-orders') => Http::response([
                'code' => '00000',
                'data' => ['list' => [], 'cursor' => null],
            ], 200),
            str_contains($request->url(), '/api/v3/account/info') => Http::response([
                'code' => '00000',
                'data' => ['permissions' => ['uta_trade']],
            ], 200),
            default => Http::response(['message' => 'Unexpected test URL'], 500),
        };
    });

    $result = runConnectivityChildStep($account, $server);

    expect($result['result'])->toBe('ok')
        ->and($result['balance_checked'])->toBeTrue()
        ->and($result['open_orders_count'])->toBe(0)
        ->and($result['withdrawals_enabled'])->toBeFalse();

    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), '/api/v2/mix/'));
});

it('translates a Bitget missing-management-scope refusal into a plain permission message', function (): void {
    $account = createConnectivityAccount('bitget');
    $account->forceFill([
        'bitget_api_key' => 'uta-noscope-key',
        'bitget_api_secret' => 'uta-noscope-secret',
        'bitget_passphrase' => 'uta-noscope-passphrase',
        'bitget_account_mode' => 'unified',
    ])->save();
    $server = createConnectivityServer();

    Http::fake([
        'api.bitget.com/api/v3/account/assets*' => Http::response([
            'code' => '40014',
            'msg' => 'Incorrect permissions, need UTA manage read or UTA manage write permissions',
        ], 400),
    ]);

    expect(fn (): array => runConnectivityChildStep($account, $server))
        ->toThrow(Exception::class, 'The API key is missing a required permission on Bitget: enable "Unified account management (read-only)" on the key, then run the test again.');
});

it('translates a Bitget non-whitelisted IP refusal into a plain whitelist message', function (): void {
    $account = createConnectivityAccount('bitget');
    $account->forceFill([
        'bitget_api_key' => 'classic-noip-key',
        'bitget_api_secret' => 'classic-noip-secret',
        'bitget_passphrase' => 'classic-noip-passphrase',
        'bitget_account_mode' => 'classic',
    ])->save();
    $server = createConnectivityServer();

    Http::fake([
        'api.bitget.com/api/v2/mix/account/accounts*' => Http::response([
            'code' => '40018',
            'msg' => 'Invalid IP',
        ], 400),
    ]);

    expect(fn (): array => runConnectivityChildStep($account, $server))
        ->toThrow(Exception::class, "This server's IP is not whitelisted on Bitget. Add every listed IP address to the API key, then run the test again.");
});

it('translates the ambiguous Binance -2015 refusal into the combined key/permission/IP message', function (): void {
    $account = createConnectivityAccount();
    $account->forceFill([
        'binance_api_key' => 'ambiguous-2015-key',
        'binance_api_secret' => 'ambiguous-2015-secret',
    ])->save();
    $server = createConnectivityServer();

    Http::fake([
        'api.binance.com/*' => Http::response([
            'code' => -2015,
            'msg' => 'Invalid API-key, IP, or permissions for action.',
        ], 401),
    ]);

    expect(fn (): array => runConnectivityChildStep($account, $server))
        ->toThrow(Exception::class, 'Binance rejected this API key — it is invalid, missing permissions, or a server IP is not whitelisted. Fix the key (every permission and every IP listed), then run the test again.');
});

it('reports a failed parent step as a complete run with every server not connected', function (): void {
    $account = createConnectivityAccount();
    createConnectivityServer(['hostname' => 'apollo', 'ip_address' => '203.0.113.10']);
    createConnectivityServer(['hostname' => 'ares', 'ip_address' => '203.0.113.11']);

    $payload = app(AccountServerConnectivityService::class)->start($account);

    Step::query()
        ->where('block_uuid', $payload['block_uuid'])
        ->update([
            'state' => Failed::class,
            'error_message' => 'NoCleanWorkerException: routing refused before any probe ran.',
        ]);

    $status = app(AccountServerConnectivityService::class)->status($payload['block_uuid']);
    $rows = collect($status['servers']);

    expect($status['is_complete'])->toBeTrue()
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('status')->unique()->all())->toBe(['not_connected'])
        ->and($rows->first()['error_message'])->toBe('NoCleanWorkerException: routing refused before any probe ran.');
});

it('keeps reporting testing while the parent is still pending without children', function (): void {
    $account = createConnectivityAccount();
    createConnectivityServer(['hostname' => 'apollo', 'ip_address' => '203.0.113.10']);

    $payload = app(AccountServerConnectivityService::class)->start($account);

    $status = app(AccountServerConnectivityService::class)->status($payload['block_uuid']);

    expect($status['is_complete'])->toBeFalse()
        ->and(collect($status['servers'])->pluck('status')->unique()->all())->toBe(['testing']);
});

it('queues the connectivity orchestrator on the priority lane, never behind bulk work', function (): void {
    $account = createConnectivityAccount();
    createConnectivityServer(['hostname' => 'apollo', 'ip_address' => '203.0.113.10']);

    $payload = app(AccountServerConnectivityService::class)->start($account);

    $parent = Step::query()
        ->where('block_uuid', $payload['block_uuid'])
        ->where('class', TestExchangeConnectivityStep::class)
        ->sole();

    expect($parent->queue)->toBe('priority')
        ->and($parent->priority)->toBe('high');
});

it('creates the per-server probe children on the dispatcher fast pass', function (): void {
    $account = createConnectivityAccount();
    createConnectivityServer(['hostname' => 'apollo', 'ip_address' => '203.0.113.10']);
    createConnectivityServer(['hostname' => 'ares', 'ip_address' => '203.0.113.11']);

    $payload = app(AccountServerConnectivityService::class)->start($account);
    $parent = Step::query()->where('block_uuid', $payload['block_uuid'])->sole();
    runConnectivityParentStep($parent->fresh(), $account);

    $children = Step::query()
        ->where('block_uuid', $parent->fresh()->child_block_uuid)
        ->where('class', TestServerConnectivityStep::class)
        ->get();

    expect($children)->toHaveCount(2)
        ->and($children->pluck('priority')->unique()->all())->toBe(['high']);
});
