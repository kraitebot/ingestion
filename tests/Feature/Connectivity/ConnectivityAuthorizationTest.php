<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Lifecycles\Account\TestExchangeConnectivityStep;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\User;
use StepDispatcher\Models\Step;

/**
 * Authorization on the connectivity endpoints (core 1.64.x). The routes
 * require only `auth`; the controller now additionally authorizes every
 * action against the `operate` ability on the target Account (owner or
 * admin). These tests pin cross-user access → 403, owner/admin → allowed.
 * See review-diff 03-High.
 */
uses(RefreshDatabase::class)->group('feature', 'connectivity', 'authorization');

function ownedAccount(): Account
{
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance', 'name' => 'Binance']);

    return Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'user_id' => User::factory()->create(['is_admin' => false])->id,
    ]);
}

it('forbids a non-owner from starting a connectivity check', function (): void {
    $account = ownedAccount();
    $stranger = User::factory()->create(['is_admin' => false]);

    $this->actingAs($stranger)
        ->postJson("/api/connectivity-test/accounts/{$account->id}/start")
        ->assertForbidden();
});

it('forbids a non-owner from triggering a whitelist notification', function (): void {
    $account = ownedAccount();
    $stranger = User::factory()->create(['is_admin' => false]);
    $serverId = DB::table('servers')->insertGetId([
        'hostname' => 'apollo', 'ip_address' => '203.0.113.10', 'is_apiable' => true,
        'needs_whitelisting' => true, 'own_queue_name' => 'default', 'description' => 'W',
        'type' => 'worker', 'secret' => null, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->actingAs($stranger)
        ->postJson("/api/connectivity-test/accounts/{$account->id}/notify-server", ['server_id' => $serverId])
        ->assertForbidden();
});

it('forbids a non-owner from reading another account workflow status', function (): void {
    $account = ownedAccount();
    $stranger = User::factory()->create(['is_admin' => false]);
    $blockUuid = (string) Str::uuid();
    Step::create([
        'class' => TestExchangeConnectivityStep::class,
        'queue' => 'default',
        'block_uuid' => $blockUuid,
        'relatable_type' => Account::class,
        'relatable_id' => $account->id,
    ]);

    $this->actingAs($stranger)
        ->getJson("/api/connectivity-test/status/{$blockUuid}")
        ->assertForbidden();
});

it('allows the owner to start a connectivity check', function (): void {
    $account = ownedAccount();
    $owner = User::find($account->user_id);

    $this->actingAs($owner)
        ->postJson("/api/connectivity-test/accounts/{$account->id}/start")
        ->assertSuccessful();
});

it('allows an admin to read any account workflow status', function (): void {
    $account = ownedAccount();
    $admin = User::factory()->create(['is_admin' => true]);
    $blockUuid = (string) Str::uuid();
    Step::create([
        'class' => TestExchangeConnectivityStep::class,
        'queue' => 'default',
        'block_uuid' => $blockUuid,
        'relatable_type' => Account::class,
        'relatable_id' => $account->id,
    ]);

    $this->actingAs($admin)
        ->getJson("/api/connectivity-test/status/{$blockUuid}")
        ->assertSuccessful();
});

it('still requires authentication', function (): void {
    $account = ownedAccount();

    $this->postJson("/api/connectivity-test/accounts/{$account->id}/start")
        ->assertUnauthorized();
});
