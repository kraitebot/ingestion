<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Kraite\Core\Contracts\PositionCloseAttributor;
use Kraite\Core\Enums\PositionCloseAttribution;
use Kraite\Core\Jobs\Atomic\Position\CancelPositionOpenOrdersJob;
use Kraite\Core\Jobs\Atomic\Position\VerifyPositionExistsOnExchangeJob;
use Kraite\Core\Jobs\Lifecycles\Position\ClosePositionJob;
use Kraite\Core\Jobs\Lifecycles\Position\PreparePositionReplacementJob;
use Kraite\Core\Jobs\Lifecycles\Position\SmartReplaceOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\PositionCloseEvidence;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

function replacementConfirmationPosition(string $direction = 'LONG'): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);
    $symbol = Symbol::factory()->create(['token' => 'REPLACE']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'token' => 'REPLACE',
        'quote' => 'USDT',
    ]);
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        'binance_api_key' => 'TESTKEY',
        'binance_api_secret' => 'TESTSECRET',
    ]);
    $position = Position::factory()->opened()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'REPLACEUSDT',
        'direction' => $direction,
        'status' => 'active',
    ]);

    Order::withoutEvents(fn (): Order => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => 'REPLACE-LIMIT-1',
        'type' => 'LIMIT',
        'side' => $direction === 'LONG' ? 'BUY' : 'SELL',
        'position_side' => $direction,
        'status' => 'NEW',
        'reference_status' => 'NEW',
        'price' => '1',
        'quantity' => '10',
        'reference_price' => '1',
        'reference_quantity' => '10',
        'is_algo' => false,
    ]));

    return $position;
}

function runReplacementVerifier(
    Position $position,
    bool $confirmationAttempt = false,
    bool $manualCloseDetected = false,
    ?int $flatObservedAtMs = null,
    ?string $manualClosingPrice = null,
): array {
    return Steps::usingPrefix('trading', function () use ($position, $confirmationAttempt, $manualCloseDetected, $flatObservedAtMs, $manualClosingPrice): array {
        $step = Step::create([
            'class' => VerifyPositionExistsOnExchangeJob::class,
            'queue' => 'positions',
            'priority' => 'high',
            'relatable_type' => $position->getMorphClass(),
            'relatable_id' => $position->id,
            'arguments' => [],
        ]);
        $job = new VerifyPositionExistsOnExchangeJob(
            positionId: $position->id,
            triggerStatus: 'CANCELLED',
            message: 'test replacement',
            confirmationAttempt: $confirmationAttempt,
            manualCloseDetected: $manualCloseDetected,
            flatObservedAtMs: $flatObservedAtMs,
            manualClosingPrice: $manualClosingPrice,
        );
        $job->step = $step;

        return $job->compute();
    });
}

function replacementSteps(Position $position, string $class): Illuminate\Database\Eloquent\Collection
{
    return Steps::usingPrefix('trading', fn () => Step::query()
        ->forRelatable($position)
        ->forClasses($class)
        ->get());
}

it('keeps the position active and schedules a delayed re-query after the first valid absence', function (): void {
    $position = replacementConfirmationPosition();
    ApiSnapshot::storeFor($position->account, 'account-positions', []);

    $result = runReplacementVerifier($position);
    $confirmations = replacementSteps($position, PreparePositionReplacementJob::class);

    expect($position->refresh()->status)->toBe('active')
        ->and($result['position_exists_on_exchange'])->toBeFalse()
        ->and($confirmations)->toHaveCount(1)
        ->and($confirmations->first()->arguments['confirmationAttempt'])->toBeTrue()
        ->and($confirmations->first()->arguments['flatObservedAtMs'])->toBe(now()->getTimestampMs())
        ->and($confirmations->first()->priority)->toBe('high')
        ->and($confirmations->first()->dispatch_after->isFuture())->toBeTrue()
        ->and(replacementSteps($position, ClosePositionJob::class))->toHaveCount(0)
        ->and(replacementSteps($position, CancelPositionOpenOrdersJob::class))->toHaveCount(0);
});

it('carries a manual-close signal through confirmation without marking an unconfirmed absence', function (): void {
    $position = replacementConfirmationPosition();
    ApiSnapshot::storeFor($position->account, 'account-positions', []);

    runReplacementVerifier(
        $position,
        manualCloseDetected: true,
        manualClosingPrice: '1.23450000',
    );
    $confirmation = replacementSteps($position, PreparePositionReplacementJob::class)->sole();

    expect($position->refresh()->manually_closed_at)->toBeNull()
        ->and($confirmation->arguments['manualCloseDetected'])->toBeTrue()
        ->and($confirmation->arguments['manualClosingPrice'])->toBe('1.23450000');
});

it('cancels opening orders before closing locally after confirmed absence', function (): void {
    $position = replacementConfirmationPosition();
    ApiSnapshot::storeFor($position->account, 'account-positions', []);

    $result = runReplacementVerifier($position, confirmationAttempt: true);

    expect($position->refresh()->status)->toBe('closing')
        ->and($result['position_exists_on_exchange'])->toBeFalse()
        ->and(replacementSteps($position, CancelPositionOpenOrdersJob::class))->toHaveCount(1)
        ->and(replacementSteps($position, ClosePositionJob::class))->toHaveCount(1)
        ->and(replacementSteps($position, ClosePositionJob::class)->sole()->arguments['positionConfirmedFlat'])->toBeTrue();
});

it('records a manual close only after the exchange confirms the position flat', function (): void {
    $position = replacementConfirmationPosition();
    ApiSnapshot::storeFor($position->account, 'account-positions', []);

    expect($position->manually_closed_at)->toBeNull();

    $attributor = Mockery::mock(PositionCloseAttributor::class);
    $attributor->shouldNotReceive('resolveEvidence');
    app()->instance(PositionCloseAttributor::class, $attributor);

    runReplacementVerifier(
        $position,
        confirmationAttempt: true,
        manualCloseDetected: true,
        manualClosingPrice: '1.23450000',
    );

    expect($position->refresh()->manually_closed_at?->toIso8601String())
        ->toBe(now()->toIso8601String())
        ->and((string) $position->closing_price)->toContain('1.2345');
});

it('recovers a dropped manual-close event only after confirmed flat evidence', function (): void {
    $position = replacementConfirmationPosition();
    ApiSnapshot::storeFor($position->account, 'account-positions', []);
    $flatObservedAtMs = now()->subSecond()->getTimestampMs();
    $attributor = Mockery::mock(PositionCloseAttributor::class);
    $attributor->shouldReceive('resolveEvidence')
        ->once()
        ->withArgs(fn (Position $candidate, int $observedAt): bool => $candidate->is($position)
            && $observedAt === $flatObservedAtMs)
        ->andReturn(new PositionCloseEvidence(PositionCloseAttribution::External, '1.11110000'));
    app()->instance(PositionCloseAttributor::class, $attributor);

    runReplacementVerifier(
        $position,
        confirmationAttempt: true,
        flatObservedAtMs: $flatObservedAtMs,
    );

    expect($position->refresh()->manually_closed_at?->toIso8601String())
        ->toBe(now()->toIso8601String())
        ->and((string) $position->closing_price)->toContain('1.1111')
        ->and(replacementSteps($position, ClosePositionJob::class))->toHaveCount(1);
});

it('never labels automatic forced or ambiguous confirmed-flat closes as manual', function (PositionCloseAttribution $attribution): void {
    $position = replacementConfirmationPosition();
    ApiSnapshot::storeFor($position->account, 'account-positions', []);
    $attributor = Mockery::mock(PositionCloseAttributor::class);
    $attributor->shouldReceive('resolveEvidence')
        ->once()
        ->andReturn(new PositionCloseEvidence($attribution, '9.99990000'));
    app()->instance(PositionCloseAttributor::class, $attributor);

    runReplacementVerifier(
        $position,
        confirmationAttempt: true,
        flatObservedAtMs: now()->getTimestampMs(),
    );

    expect($position->refresh()->manually_closed_at)->toBeNull()
        ->and($position->closing_price)->toBeNull()
        ->and($position->status)->toBe('closing')
        ->and(replacementSteps($position, ClosePositionJob::class))->toHaveCount(1);
})->with([
    'Kraite order' => PositionCloseAttribution::Kraite,
    'liquidation or ADL' => PositionCloseAttribution::Forced,
    'insufficient evidence' => PositionCloseAttribution::Unknown,
]);

it('does not confuse an opposite hedge side with the local position', function (): void {
    $position = replacementConfirmationPosition('LONG');
    ApiSnapshot::storeFor($position->account, 'account-positions', [
        'REPLACEUSDT:SHORT' => [
            'symbol' => 'REPLACEUSDT',
            'positionSide' => 'SHORT',
            'positionAmt' => '-5',
        ],
    ]);

    runReplacementVerifier($position);

    expect(replacementSteps($position, PreparePositionReplacementJob::class))->toHaveCount(1)
        ->and(replacementSteps($position, SmartReplaceOrdersJob::class))->toHaveCount(0);
});

it('replaces orders immediately when the exact position is still open', function (): void {
    $position = replacementConfirmationPosition('SHORT');
    ApiSnapshot::storeFor($position->account, 'account-positions', [
        'REPLACEUSDT:BOTH' => [
            'symbol' => 'REPLACEUSDT',
            'positionSide' => 'BOTH',
            'positionAmt' => '-5',
        ],
    ]);

    $result = runReplacementVerifier($position);

    expect($result['position_exists_on_exchange'])->toBeTrue()
        ->and(replacementSteps($position, SmartReplaceOrdersJob::class))->toHaveCount(1)
        ->and(replacementSteps($position, PreparePositionReplacementJob::class))->toHaveCount(0);
});

it('does not mark a manual close when the exchange still reports exposure', function (): void {
    $position = replacementConfirmationPosition('SHORT');
    ApiSnapshot::storeFor($position->account, 'account-positions', [
        'REPLACEUSDT:BOTH' => [
            'symbol' => 'REPLACEUSDT',
            'positionSide' => 'BOTH',
            'positionAmt' => '-5',
        ],
    ]);

    runReplacementVerifier($position, manualCloseDetected: true);

    expect($position->refresh()->manually_closed_at)->toBeNull();
});
