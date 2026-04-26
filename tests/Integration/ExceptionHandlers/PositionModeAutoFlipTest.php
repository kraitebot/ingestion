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
use Tests\Support\TestBinanceApiableJob;

uses(RefreshDatabase::class)->group('integration', 'exception-handlers', 'binance', 'position-mode');

beforeEach(function () {
    Kraite::create([
        'id' => 1,
        'email' => 'admin@test.com',
        'admin_pushover_user_key' => 'test_key',
        'admin_pushover_application_key' => 'test_app_key',
        'notification_channels' => ['mail'],
    ]);
});

/**
 * Position-mode auto-flip catch.
 *
 * When Binance returns a position-side mismatch (-4060 / -4061 / -4062),
 * the handler MUST:
 *   1. Atomically flip `accounts.on_hedge_mode` (lockForUpdate to handle
 *      concurrent failures from sibling atomic jobs).
 *   2. Reschedule the failing step via rescheduleWithoutRetry — no
 *      retry-counter burn, no Position::updateToFailed cascade, no
 *      symbol auto-block. The next dispatcher tick re-runs the same
 *      step with the corrected payload.
 *   3. Audit the flip in BOTH Log::warning (ops grep) AND
 *      Account::modelLog (per-account forensic timeline).
 *
 * The catch sits ahead of every other RequestException branch in
 * HandlesApiJobExceptions::handleApiException so position-mode
 * mismatches never get miscategorised as IP-bans, rate limits, or
 * recvWindow drift.
 *
 * The catch listens for a FAMILY of error codes (-4060, -4061, -4062,
 * -4067) so a Binance-side rename or asymmetric-code variant doesn't
 * silently break the auto-flip.
 */
function buildHedgeAccount(): Account
{
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $user = User::factory()->create();

    return Account::factory()->hedgeMode()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);
}

function buildOneWayAccount(): Account
{
    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance']);
    $user = User::factory()->create();

    return Account::factory()->oneWayMode()->create([
        'user_id' => $user->id,
        'api_system_id' => $apiSystem->id,
    ]);
}

it('flips on_hedge_mode from true to false when Binance returns -4061', function () {
    $account = buildHedgeAccount();

    expect($account->on_hedge_mode)->toBeTrue();

    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'binancePositionSideMismatch',
        ]],
    ], TestBinanceApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('position_mode_flip_hedge_to_oneway')
        ->test();

    $account->refresh();
    expect($account->on_hedge_mode)->toBeFalse(
        'A -4061 against a hedge-mode account means the live exchange is in one-way mode; '
        .'flag must auto-correct to false so the next attempt sends a one-way payload.'
    );

    $step->refresh();
    expect($step->state->value())->toBe('pending');
    expect($step->dispatch_after)->not->toBeNull();
    expect($step->dispatch_after->isFuture())->toBeTrue();
});

it('flips on_hedge_mode from false to true when Binance returns -4061', function () {
    $account = buildOneWayAccount();

    expect($account->on_hedge_mode)->toBeFalse();

    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'binancePositionSideMismatch',
        ]],
    ], TestBinanceApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([
            1 => [$step->id => 'pending'],
        ])
        ->withLabel('position_mode_flip_oneway_to_hedge')
        ->test();

    $account->refresh();
    expect($account->on_hedge_mode)->toBeTrue(
        'Symmetric flip: a -4061 against a one-way-mode account means the live exchange is in '
        .'hedge mode; flag must auto-correct to true.'
    );
});

it('flips on -4060 (INVALID_POSITION_SIDE) — family-of-codes coverage', function () {
    $account = buildHedgeAccount();

    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'binanceInvalidPositionSide',
        ]],
    ], TestBinanceApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([1 => [$step->id => 'pending']])
        ->withLabel('position_mode_flip_4060')
        ->test();

    expect($account->fresh()->on_hedge_mode)->toBeFalse();
});

it('flips on -4062 (REDUCE_ONLY_CONFLICT) — family-of-codes coverage', function () {
    $account = buildHedgeAccount();

    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'binanceReduceOnlyConflict',
        ]],
    ], TestBinanceApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([1 => [$step->id => 'pending']])
        ->withLabel('position_mode_flip_4062')
        ->test();

    expect($account->fresh()->on_hedge_mode)->toBeFalse();
});

it('writes Log::warning AND Account::modelLog when flipping', function () {
    $account = buildHedgeAccount();
    Log::spy();

    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'binancePositionSideMismatch',
        ]],
    ], TestBinanceApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([1 => [$step->id => 'pending']])
        ->withLabel('position_mode_audit_trail')
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
        .'shows when a flip happened and which job triggered it.'
    );

    expect($logRow->metadata['previous'] ?? null)->toBeTrue();
    expect($logRow->metadata['new'] ?? null)->toBeFalse();
});

it('does NOT flip on an unrelated RequestException (-2015 IP not whitelisted)', function () {
    $account = buildHedgeAccount();

    $step = StepTester::createSteps([
        ['arguments' => [
            'accountId' => $account->id,
            'throw_exception_stub' => 'binanceIpNotWhitelisted',
        ]],
    ], TestBinanceApiableJob::class)[0];

    StepTester::withSteps([$step])
        ->withStatusMatrix([1 => [$step->id => 'pending']])
        ->withLabel('position_mode_no_false_positive')
        ->test();

    expect($account->fresh()->on_hedge_mode)->toBeTrue(
        'Only the position-side family (-4060/-4061/-4062/-4067) should trigger the flip. '
        .'Other RequestExceptions must not poison the flag.'
    );
});
