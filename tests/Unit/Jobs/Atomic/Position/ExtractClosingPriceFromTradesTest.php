<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\UpdateRemainingClosingDataJob;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\Symbol;

/**
 * Builds a real persisted Position so UpdateRemainingClosingDataJob can
 * resolve one via findOrFail in its constructor. Actual computeApiable()
 * is not invoked by these tests — only the private extractor.
 */
function buildPositionForExtractorTest(): Position
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => 'API3']);

    $exchangeSymbol = ExchangeSymbol::factory()->create([
        'token' => 'API3',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
    ]);

    $account = Account::factory()->create([
        'api_system_id' => $apiSystem->id,
    ]);

    return Position::factory()->create([
        'account_id' => $account->id,
        'exchange_symbol_id' => $exchangeSymbol->id,
        'direction' => 'SHORT',
    ]);
}

function invokeExtractor(array $trades, string $direction): ?string
{
    $position = buildPositionForExtractorTest();
    $job = new UpdateRemainingClosingDataJob($position->id);

    $method = new ReflectionMethod($job, 'extractClosingPriceFromTrades');

    return $method->invoke($job, $trades, $direction);
}

it('accepts a string direction as second argument', function (): void {
    $reflection = new ReflectionMethod(
        UpdateRemainingClosingDataJob::class,
        'extractClosingPriceFromTrades'
    );

    $params = $reflection->getParameters();

    expect($params)->toHaveCount(2);
    expect($params[0]->getName())->toBe('trades');
    expect($params[1]->getName())->toBe('direction');
    expect((string) $params[1]->getType())->toBe('string');
});

it('returns null for an empty trades array', function (): void {
    expect(invokeExtractor([], 'SHORT'))->toBeNull();
});

it('picks the BUY trade to close a SHORT position', function (): void {
    $trades = [
        ['side' => 'SELL', 'positionSide' => 'SHORT', 'price' => '0.3014', 'time' => 1000],
        ['side' => 'BUY', 'positionSide' => 'SHORT', 'price' => '0.3024', 'time' => 2000],
    ];

    expect(invokeExtractor($trades, 'SHORT'))->toBe('0.3024');
});

it('picks the SELL trade to close a LONG position', function (): void {
    $trades = [
        ['side' => 'BUY', 'positionSide' => 'LONG', 'price' => '6.894', 'time' => 1000],
        ['side' => 'SELL', 'positionSide' => 'LONG', 'price' => '6.890', 'time' => 2000],
    ];

    expect(invokeExtractor($trades, 'LONG'))->toBe('6.890');
});

it('scans newest first and returns the most recent matching trade', function (): void {
    $trades = [
        ['side' => 'SELL', 'positionSide' => 'LONG', 'price' => '6.900', 'time' => 1000],
        ['side' => 'BUY', 'positionSide' => 'LONG', 'price' => '6.950', 'time' => 2000],
        ['side' => 'SELL', 'positionSide' => 'LONG', 'price' => '6.890', 'time' => 3000],
    ];

    expect(invokeExtractor($trades, 'LONG'))->toBe('6.890');
});

it('falls back to the last trade when positionSide is absent', function (): void {
    $trades = [
        ['side' => 'BUY', 'price' => '6.894'],
        ['side' => 'SELL', 'price' => '6.890'],
    ];

    expect(invokeExtractor($trades, 'LONG'))->toBe('6.890');
});

it('accepts lowercase direction input', function (): void {
    $trades = [
        ['side' => 'BUY', 'positionSide' => 'SHORT', 'price' => '0.3024', 'time' => 1000],
    ];

    expect(invokeExtractor($trades, 'short'))->toBe('0.3024');
});

it('ignores trades whose price is zero or missing', function (): void {
    $trades = [
        ['side' => 'SELL', 'positionSide' => 'LONG', 'price' => '0', 'time' => 1000],
        ['side' => 'SELL', 'positionSide' => 'LONG', 'price' => '6.890', 'time' => 2000],
    ];

    expect(invokeExtractor($trades, 'LONG'))->toBe('6.890');
});

// =============================================================================
// One-way mode — Binance tags every userTrades fill positionSide=BOTH.
// The side-verified match must treat BOTH as a wildcard, otherwise the
// extractor silently degrades to the side-blind last-trade fallback and
// can record a non-reducing fill (e.g. the user's own trade on an
// allow_other_positions account) as the closing price.
// =============================================================================

it('one-way: picks the SELL close among BOTH-tagged trades even when a later BUY fill exists', function (): void {
    $trades = [
        ['side' => 'SELL', 'positionSide' => 'BOTH', 'price' => '6.890', 'time' => 1000],
        ['side' => 'BUY', 'positionSide' => 'BOTH', 'price' => '6.950', 'time' => 2000],
    ];

    expect(invokeExtractor($trades, 'LONG'))->toBe('6.890');
});

it('one-way: picks the BUY close among BOTH-tagged trades even when a later SELL fill exists', function (): void {
    $trades = [
        ['side' => 'BUY', 'positionSide' => 'BOTH', 'price' => '0.3024', 'time' => 1000],
        ['side' => 'SELL', 'positionSide' => 'BOTH', 'price' => '0.3014', 'time' => 2000],
    ];

    expect(invokeExtractor($trades, 'SHORT'))->toBe('0.3024');
});

it('one-way: returns the most recent BOTH-tagged closing fill when several exist', function (): void {
    $trades = [
        ['side' => 'SELL', 'positionSide' => 'BOTH', 'price' => '6.900', 'time' => 1000],
        ['side' => 'SELL', 'positionSide' => 'BOTH', 'price' => '6.890', 'time' => 2000],
    ];

    expect(invokeExtractor($trades, 'LONG'))->toBe('6.890');
});

it('one-way: falls back to the last trade when no BOTH-tagged fill matches the closing side', function (): void {
    $trades = [
        ['side' => 'BUY', 'positionSide' => 'BOTH', 'price' => '6.894', 'time' => 1000],
        ['side' => 'BUY', 'positionSide' => 'BOTH', 'price' => '6.950', 'time' => 2000],
    ];

    expect(invokeExtractor($trades, 'LONG'))->toBe('6.950');
});
