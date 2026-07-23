<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\VerifyPositionResidualAmountJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSnapshot;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * Pin the close-side residual safeguard. After a close lifecycle ran,
 * this job inspects the latest account-positions snapshot to detect a
 * non-zero remaining quantity on the same trading pair — which means
 * the close call did not flatten the position fully and operator
 * intervention is needed. A residual or untrusted snapshot must fail the
 * lifecycle before its terminal local status step can run.
 *
 * False negatives here ship as silent partial closes (the bot believes
 * the position is gone but the exchange still carries inventory). False
 * positives spam the admins with phantom alerts and erode signal trust.
 *
 * Cases pinned:
 *
 *   - Snapshot missing or malformed → lifecycle fails closed.
 *   - Valid empty snapshot → no residual.
 *   - Snapshot keyed by symbol with positionAmt = 0 → no residual.
 *   - Snapshot has positive positionAmt for our pair → residual flagged.
 *   - Snapshot has negative positionAmt (SHORT) → absolute value flagged.
 *   - Snapshot covers other symbols only → our pair returns clean.
 *   - Field-name variance: `size`, `qty`, `available` all parsed
 *     correctly across exchange shape differences.
 */
function buildPositionForResidual(string $direction = 'LONG', string $token = 'BTC'): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create(['canonical' => 'binance']);
    $account = Account::factory()->create(['api_system_id' => $apiSystem->id]);
    $symbol = Symbol::factory()->create(['cmc_id' => random_int(1_000_000, 9_999_999)]);
    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'symbol_id' => $symbol->id,
        'api_system_id' => $apiSystem->id,
        'token' => $token,
        'quote' => 'USDT',
    ]);

    return Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => $direction,
        'status' => 'closing',
        'parsed_trading_pair' => $token.'USDT',
    ]);
}

function seedAccountPositionsForResidual(Position $position, array $payload): void
{
    ApiSnapshot::updateOrCreate(
        [
            'responsable_type' => Account::class,
            'responsable_id' => $position->account_id,
            'canonical' => 'account-positions',
        ],
        ['api_response' => $payload],
    );
}

it('fails closed when the account-positions snapshot is missing entirely', function (): void {
    $position = buildPositionForResidual();

    expect(fn () => (new VerifyPositionResidualAmountJob($position->id))->compute())
        ->toThrow(UnexpectedValueException::class, 'Trusted account-positions snapshot is missing')
        ->and($position->refresh()->status)->toBe('closing');
});

it('returns no-residual when the snapshot exists but the matching pair carries quantity zero', function (): void {
    $position = buildPositionForResidual(token: 'BTC');

    seedAccountPositionsForResidual($position, [
        'BTCUSDT:LONG' => ['symbol' => 'BTCUSDT', 'positionAmt' => '0'],
    ]);

    $result = (new VerifyPositionResidualAmountJob($position->id))->compute();

    expect($result['has_residual'])->toBeFalse();
});

it('flags residual when the matching pair still carries a positive quantity', function (): void {
    $position = buildPositionForResidual(token: 'BTC');

    seedAccountPositionsForResidual($position, [
        'BTCUSDT:LONG' => ['symbol' => 'BTCUSDT', 'positionAmt' => '0.025'],
    ]);

    expect(fn () => (new VerifyPositionResidualAmountJob($position->id))->compute())
        ->toThrow(UnexpectedValueException::class, 'Residual amount 0.025 found for BTCUSDT')
        ->and($position->refresh()->status)->toBe('closing');
});

it('flags residual using the absolute value when the snapshot reports a negative SHORT amount', function (): void {
    // SHORT positions in Binance one-way mode land in the snapshot
    // as a NEGATIVE positionAmt. The job must report the magnitude
    // (absolute value), not the raw signed value, so downstream
    // alerts read the inventory honestly.
    $position = buildPositionForResidual(direction: 'SHORT', token: 'BTC');

    seedAccountPositionsForResidual($position, [
        'BTCUSDT:SHORT' => [
            'symbol' => 'BTCUSDT',
            'positionSide' => 'SHORT',
            'positionAmt' => '-0.7',
        ],
    ]);

    expect(fn () => (new VerifyPositionResidualAmountJob($position->id))->compute())
        ->toThrow(UnexpectedValueException::class, 'Residual amount 0.7 found for BTCUSDT')
        ->and($position->refresh()->status)->toBe('closing');
});

it('ignores an open hedge position on the opposite direction', function (): void {
    $position = buildPositionForResidual(direction: 'LONG', token: 'BTC');

    seedAccountPositionsForResidual($position, [
        'BTCUSDT:SHORT' => [
            'symbol' => 'BTCUSDT',
            'positionSide' => 'SHORT',
            'positionAmt' => '-0.7',
        ],
    ]);

    $result = (new VerifyPositionResidualAmountJob($position->id))->compute();

    expect($result['has_residual'])->toBeFalse()
        ->and($result['residual_amount'])->toBe('0')
        ->and($position->refresh()->status)->toBe('closing');
});

it('fails closed when the account-positions snapshot is malformed', function (): void {
    $position = buildPositionForResidual(token: 'BTC');

    seedAccountPositionsForResidual($position, [
        'BTCUSDT:LONG' => [
            'symbol' => 'BTCUSDT',
            'positionSide' => 'UNKNOWN',
            'positionAmt' => '0.7',
        ],
    ]);

    expect(fn () => (new VerifyPositionResidualAmountJob($position->id))->compute())
        ->toThrow(UnexpectedValueException::class, 'Trusted account-positions snapshot is malformed')
        ->and($position->refresh()->status)->toBe('closing');
});

it('returns no-residual when the snapshot only covers other symbols', function (): void {
    $position = buildPositionForResidual(token: 'BTC');

    seedAccountPositionsForResidual($position, [
        'ETHUSDT:LONG' => ['symbol' => 'ETHUSDT', 'positionAmt' => '5'],
        'SOLUSDT:LONG' => ['symbol' => 'SOLUSDT', 'positionAmt' => '12'],
    ]);

    $result = (new VerifyPositionResidualAmountJob($position->id))->compute();

    expect($result['has_residual'])->toBeFalse();
});

it('reads the residual amount from the `size` field (Bitget shape)', function (): void {
    $position = buildPositionForResidual(token: 'BTC');

    seedAccountPositionsForResidual($position, [
        'BTCUSDT:LONG' => ['symbol' => 'BTCUSDT', 'size' => '0.4'],
    ]);

    expect(fn () => (new VerifyPositionResidualAmountJob($position->id))->compute())
        ->toThrow(UnexpectedValueException::class, 'Residual amount 0.4 found for BTCUSDT');
});

it('reads the residual amount from the `qty` field (Bybit shape)', function (): void {
    $position = buildPositionForResidual(token: 'BTC');

    seedAccountPositionsForResidual($position, [
        'BTCUSDT:LONG' => ['symbol' => 'BTCUSDT', 'qty' => '1.5'],
    ]);

    expect(fn () => (new VerifyPositionResidualAmountJob($position->id))->compute())
        ->toThrow(UnexpectedValueException::class, 'Residual amount 1.5 found for BTCUSDT');
});

it('reads the residual amount from the `available` field (KuCoin shape)', function (): void {
    $position = buildPositionForResidual(token: 'BTC');

    seedAccountPositionsForResidual($position, [
        'BTCUSDT:LONG' => ['symbol' => 'BTCUSDT', 'available' => '0.05'],
    ]);

    expect(fn () => (new VerifyPositionResidualAmountJob($position->id))->compute())
        ->toThrow(UnexpectedValueException::class, 'Residual amount 0.05 found for BTCUSDT');
});

it('handles indexed array shape where the symbol field is on each row', function (): void {
    $position = buildPositionForResidual(token: 'BTC');

    seedAccountPositionsForResidual($position, [
        ['symbol' => 'ETHUSDT', 'positionAmt' => '0'],
        ['symbol' => 'BTCUSDT', 'positionAmt' => '0.7'],
    ]);

    expect(fn () => (new VerifyPositionResidualAmountJob($position->id))->compute())
        ->toThrow(UnexpectedValueException::class, 'Residual amount 0.7 found for BTCUSDT');
});
