<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\DetermineLeverageJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite as KraiteModel;
use Kraite\Core\Models\Position;

/**
 * DetermineLeverageJob scales the bracket/account-determined base
 * leverage down by the BSCS regime ramp (Phase 3): floor(base × ratio),
 * clamped to a minimum of 1x. Calm = full, Elevated = 66%, Fragile = 50%.
 */
function makeLeveragePosition(int $accountMaxLeverage = 20, string $direction = 'LONG'): Position
{
    $account = Account::factory()->create([
        'position_leverage_long' => $accountMaxLeverage,
        'position_leverage_short' => $accountMaxLeverage,
    ]);

    $apiSystem = ApiSystem::factory()->create(['canonical' => 'binance', 'is_exchange' => true]);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'api_system_id' => $apiSystem->id,
        'token' => 'BTC',
        'quote' => 'USDT',
        'leverage_brackets' => [
            ['bracket' => 1, 'initialLeverage' => $accountMaxLeverage, 'notionalCap' => 100000000],
        ],
    ]);

    return Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => $direction,
        'margin' => '100',
        'status' => 'new',
    ]);
}

function setBscsScore(?int $score): void
{
    KraiteModel::find(1)->updateSaving(['bscs_score' => $score]);
}

it('keeps full leverage in the Calm band', function (): void {
    setBscsScore(10);
    $position = makeLeveragePosition(20);

    (new DetermineLeverageJob($position->id))->compute();

    expect((int) $position->fresh()->leverage)->toBe(20);
});

it('cuts leverage to 66% (floored) in the Elevated band', function (): void {
    setBscsScore(50);
    $position = makeLeveragePosition(20);

    (new DetermineLeverageJob($position->id))->compute();

    // floor(20 × 0.66) = floor(13.2) = 13
    expect((int) $position->fresh()->leverage)->toBe(13);
});

it('cuts leverage to 50% (floored) in the Fragile band', function (): void {
    setBscsScore(70);
    $position = makeLeveragePosition(20);

    (new DetermineLeverageJob($position->id))->compute();

    // floor(20 × 0.50) = 10
    expect((int) $position->fresh()->leverage)->toBe(10);
});

it('never floors leverage below 1x', function (): void {
    setBscsScore(70); // fragile 0.5
    $position = makeLeveragePosition(1); // base 1 → floor(1×0.5)=0 → clamp 1

    (new DetermineLeverageJob($position->id))->compute();

    expect((int) $position->fresh()->leverage)->toBe(1);
});

it('accepts a position notional exactly on the leverage bracket cap', function (): void {
    $position = makeLeveragePosition(20);
    $result = (new DetermineLeverageJob($position->id))->getMaxLeverageForMargin(
        margin: '100',
        brackets: [['bracket' => 1, 'initialLeverage' => 20, 'notionalCap' => 2000]],
        accountMaxLeverage: 20,
    );

    expect($result['leverage'])->toBe(20)
        ->and($result['notional'])->toBe('2000.0000000000000000')
        ->and($result['bracket'])->toBe(1);
});

it('treats an explicit null notional cap as an unlimited max-leverage bracket', function (): void {
    $position = makeLeveragePosition(20);
    $result = (new DetermineLeverageJob($position->id))->getMaxLeverageForMargin(
        margin: '100',
        brackets: [['bracket' => 1, 'initialLeverage' => 20, 'notionalCap' => null]],
        accountMaxLeverage: 20,
    );

    expect($result['leverage'])->toBe(20)
        ->and($result['notional'])->toBe('2000.0000000000000000');
});

it('rejects malformed leverage brackets instead of silently opening at 1x', function (array $bracket): void {
    $position = makeLeveragePosition(20);

    expect(fn () => (new DetermineLeverageJob($position->id))->getMaxLeverageForMargin(
        margin: '100',
        brackets: [$bracket],
        accountMaxLeverage: 20,
    ))->toThrow(RuntimeException::class, 'Invalid leverage bracket at index 0');
})->with([
    'missing bracket number' => [['initialLeverage' => 20, 'notionalCap' => 100000]],
    'missing initial leverage' => [['bracket' => 1, 'notionalCap' => 100000]],
    'zero initial leverage' => [['bracket' => 1, 'initialLeverage' => 0, 'notionalCap' => 100000]],
    'missing notional cap' => [['bracket' => 1, 'initialLeverage' => 20]],
    'non-numeric notional cap' => [['bracket' => 1, 'initialLeverage' => 20, 'notionalCap' => 'invalid']],
]);
