<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Kraite\Core\Jobs\Atomic\Position\CancelPositionOpenOrdersJob;
use Kraite\Core\Jobs\Atomic\Position\ConfirmPositionFlatAndCancelOpeningOrdersJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Order;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;
use Kraite\Core\Support\PositionSafety;
use StepDispatcher\Models\Step;
use StepDispatcher\Support\Steps;

function positionSafetyFixture(string $canonical = 'binance', string $direction = 'LONG'): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => $canonical,
        'name' => mb_ucfirst($canonical),
    ]);
    $symbol = Symbol::factory()->create(['token' => 'SAFE']);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'token' => 'SAFE',
        'quote' => 'USDT',
    ]);
    $credentials = match ($canonical) {
        'bitget' => [
            'bitget_api_key' => 'TESTKEY',
            'bitget_api_secret' => 'TESTSECRET',
            'bitget_passphrase' => 'TESTPASS',
            'bitget_account_mode' => 'classic',
        ],
        default => [
            'binance_api_key' => 'TESTKEY',
            'binance_api_secret' => 'TESTSECRET',
        ],
    };
    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
        ...$credentials,
    ]);

    $position = Position::factory()->opened()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'parsed_trading_pair' => 'SAFEUSDT',
        'direction' => $direction,
        'status' => 'active',
    ]);

    Order::withoutEvents(fn (): Order => Order::create([
        'position_id' => $position->id,
        'uuid' => Str::uuid()->toString(),
        'client_order_id' => Str::uuid()->toString(),
        'exchange_order_id' => 'SAFE-LIMIT-1',
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

function positionSafetySteps(Position $position, string $class): Illuminate\Database\Eloquent\Collection
{
    return Steps::usingPrefix('trading', fn () => Step::query()
        ->forRelatable($position)
        ->forClasses($class)
        ->get());
}

it('schedules one delayed high-priority confirmation for a first flat signal', function (): void {
    config()->set('kraite.position_safety.flat_confirmation_delay_seconds', 20);
    $position = positionSafetyFixture();

    $first = Steps::usingPrefix('trading', fn (): bool => PositionSafety::scheduleFlatConfirmation(
        $position,
        'partial-fill',
    ));
    $second = Steps::usingPrefix('trading', fn (): bool => PositionSafety::scheduleFlatConfirmation(
        $position,
        'drift',
    ));
    $steps = positionSafetySteps($position, ConfirmPositionFlatAndCancelOpeningOrdersJob::class);

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($steps)->toHaveCount(1)
        ->and($steps->first()->priority)->toBe('high')
        ->and($steps->first()->queue)->toBe('priority')
        ->and($steps->first()->dispatch_after->between(now()->addSeconds(19), now()->addSeconds(21)))->toBeTrue();
});

it('dispatches opening-order cancellation only after a second valid flat response', function (): void {
    Http::fake([
        '*/fapi/v3/positionRisk*' => Http::response('[]'),
    ]);
    $position = positionSafetyFixture();

    $result = Steps::usingPrefix('trading', fn (): array => (new ConfirmPositionFlatAndCancelOpeningOrdersJob(
        $position->id,
        'wap',
    ))->computeApiable());

    $steps = positionSafetySteps($position, CancelPositionOpenOrdersJob::class);

    expect($result['confirmed_flat'])->toBeTrue()
        ->and($result['opening_orders_cancel_dispatched'])->toBeTrue()
        ->and($steps)->toHaveCount(1)
        ->and($steps->first()->priority)->toBe('high')
        ->and($steps->first()->arguments['openingOrdersOnly'])->toBeTrue();
});

it('does not cancel when the confirmation response still contains the exact position', function (): void {
    Http::fake([
        '*/fapi/v3/positionRisk*' => Http::response(json_encode([[
            'symbol' => 'SAFEUSDT',
            'positionSide' => 'LONG',
            'positionAmt' => '10',
        ]], JSON_THROW_ON_ERROR)),
    ]);
    $position = positionSafetyFixture();

    $result = Steps::usingPrefix('trading', fn (): array => (new ConfirmPositionFlatAndCancelOpeningOrdersJob(
        $position->id,
        'wap',
    ))->computeApiable());

    expect($result['confirmed_flat'])->toBeFalse()
        ->and(positionSafetySteps($position, CancelPositionOpenOrdersJob::class))->toHaveCount(0);
});

it('rejects an unsuccessful confirmation envelope without cancelling', function (): void {
    Http::fake([
        '*/api/v2/mix/position/all-position*' => Http::response(json_encode([
            'code' => '40014',
            'msg' => 'invalid api key',
        ], JSON_THROW_ON_ERROR)),
    ]);
    $position = positionSafetyFixture('bitget');
    $job = new ConfirmPositionFlatAndCancelOpeningOrdersJob($position->id, 'drift');

    expect(positionSafetySteps($position, CancelPositionOpenOrdersJob::class))->toHaveCount(0);

    expect(fn () => Steps::usingPrefix('trading', fn () => $job->computeApiable()))
        ->toThrow(RequestException::class, 'Bitget API error (code 40014): invalid api key')
        ->and(positionSafetySteps($position, CancelPositionOpenOrdersJob::class))->toHaveCount(0);
});
