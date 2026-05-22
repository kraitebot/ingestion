<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

function createConnectivityAccount(): Account
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'user_id' => User::factory()->create()->id,
    ]);
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
