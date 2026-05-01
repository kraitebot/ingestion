<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\ModelLog;
use Kraite\Core\Models\User;
use Tests\Support\StepTester;
use Tests\Support\TestBitgetApiableJob;

uses(RefreshDatabase::class)->group('integration', 'exception-handlers', 'bitget', 'position-mode');

beforeEach(function () {
    Kraite::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);
});

/*
|--------------------------------------------------------------------------
| Bitget position-mode auto-flip
|--------------------------------------------------------------------------
|
| Mirrors the Binance auto-flip flow for Bitget. When Bitget returns
| code "40774" ("The order type for unilateral position must also be
| the unilateral position type"), the handler MUST:
|
|   1. Atomically flip `accounts.on_hedge_mode` (lockForUpdate to handle
|      concurrent failures).
|   2. Reschedule the failing step via rescheduleWithoutRetry — no
|      retry-counter burn, no Position::updateToFailed cascade.
|   3. Audit the flip in BOTH Log::warning AND Account::modelLog.
|
| The detection is shared with the Binance flow (HandlesApiJobExceptions::
| isPositionModeMismatch). Both exchanges hit the same handler — only
| the code-recognition layer differs (Binance int -4061, Bitget string
| "40774"). Pinning Bitget here keeps the cross-exchange contract honest:
| any future change to the auto-flip flow must work for both exchanges.
*/

function buildBitgetHedgeAccount(): Account
{
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();

    return Account::factory()->hedgeMode()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);
}

function buildBitgetOneWayAccount(): Account
{
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'bitget']);
    $user = User::factory()->create();

    return Account::factory()->oneWayMode()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);
}

it('flips on_hedge_mode from true to false when Bitget returns 40774', function () {
    $account = buildBitgetHedgeAccount();

    expect($account->on_hedge_mode)->toBeTrue();

    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetPositionSideMismatch',
        ]],
    ], TestBitgetApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bitget_position_mode_flip_hedge_to_oneway')
        ->test();

    $account->refresh();
    expect($account->on_hedge_mode)->toBeFalse(
        'A 40774 against a hedge-mode Bitget account means the live exchange is in one-way mode; '
        .'flag must auto-correct to false so the next attempt sends a one-way payload '
        .'(reduceOnly=YES on closes, no posSide/tradeSide/holdSide).'
    );

    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
});

it('flips on_hedge_mode from false to true when Bitget returns 40774', function () {
    $account = buildBitgetOneWayAccount();

    expect($account->on_hedge_mode)->toBeFalse();

    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetPositionSideMismatch',
        ]],
    ], TestBitgetApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('bitget_position_mode_flip_oneway_to_hedge')
        ->test();

    $account->refresh();
    expect($account->on_hedge_mode)->toBeTrue(
        'Symmetric flip: a 40774 against a one-way-mode account means the live exchange is in '
        .'hedge mode; flag must auto-correct to true (next attempt sends posSide+tradeSide+holdSide).'
    );
});

it('writes Log::warning AND Account::modelLog when flipping a Bitget account', function () {
    $account = buildBitgetHedgeAccount();
    Log::spy();

    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetPositionSideMismatch',
        ]],
    ], TestBitgetApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([1 => [$step->id => 'pending']])
        ->withLabel('bitget_position_mode_audit_trail')
        ->test();

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context) use ($account) {
            return $message === 'position_mode_auto_flip'
                && ($context['account_id'] ?? null) === $account->id
                && ($context['previous'] ?? null) === true
                && ($context['new'] ?? null) === false;
        })
        ->atLeast()->once();

    $logRow = ModelLog::where('loggable_type', Account::class)
        ->where('loggable_id', $account->id)
        ->where('event_type', 'position_mode_auto_flip')
        ->first();

    expect($logRow)->not->toBeNull(
        'Per-account forensic trail must be written to model_logs so the admin UI '
        .'shows when a Bitget account flipped and which job triggered it.'
    );

    expect($logRow->metadata['previous'] ?? null)->toBeTrue();
    expect($logRow->metadata['new'] ?? null)->toBeFalse();
});

it('does NOT flip on an unrelated Bitget RequestException (rate-limited)', function () {
    $account = buildBitgetHedgeAccount();

    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'bitgetIpRateLimited',
        ]],
    ], TestBitgetApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([1 => [$step->id => 'pending']])
        ->withLabel('bitget_position_mode_no_false_positive')
        ->test();

    expect($account->fresh()->on_hedge_mode)->toBeTrue(
        'Only the Bitget position-side family ("40774") should trigger the flip. '
        .'Other Bitget vendor codes (rate limit, account block, ignorable, etc.) '
        .'must not poison the flag.'
    );
});
