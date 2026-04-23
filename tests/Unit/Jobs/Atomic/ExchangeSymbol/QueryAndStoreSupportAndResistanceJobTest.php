<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\ExchangeSymbol\QueryAndStoreSupportAndResistanceJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Symbol;

/**
 * Regression coverage for the pivot-persistence atomic.
 *
 * Bug caught live on 2026-04-23 22:36: TAAPI returns pivotpoints wrapped
 * in a numerically-indexed array — `[{r1: ..., s1: ...}]` — because the
 * indicator config carries `results >= 1`. The atomic was dereferencing
 * `$pivots['r1']` directly, which is undefined when the outer array is
 * numerically indexed, so every level landed as null in the DB while
 * `pivot_synced_at` still got stamped. Symbols ended up with a half-
 * populated state that looked legitimate but carried no usable S/R
 * data.
 *
 * These tests pin every shape we might see coming back from TAAPI:
 * - Realistic wrapped-array shape — the failing case from the bug
 * - Hypothetical flat shape — defensive compatibility if TAAPI ever
 *   returns un-wrapped payloads
 * - Malformed / missing payloads — graceful skip with a reason
 * - Direction-cleared-between-schedule-and-execute — the atomic
 *   bails cleanly instead of persisting zombie levels
 *
 * Plus an architectural-consistency check that
 * `ConfirmPriceAlignmentWithDirectionJob` nulls the pivot columns on
 * both of its direction-invalidation paths. Without that, the bug's
 * cleanup trajectory leaves stale pivots on the row forever.
 */
function buildExchangeSymbolForPivotTest(?string $direction = 'LONG', ?array $indicatorsValues = null): ExchangeSymbol
{
    $apiSystem = ApiSystem::factory()->exchange()->create([
        'canonical' => 'binance',
        'name' => 'Binance',
    ]);

    $symbol = Symbol::factory()->create(['token' => 'PIVTEST']);

    return ExchangeSymbol::factory()->create([
        'token' => 'PIVTEST',
        'quote' => 'USDT',
        'api_system_id' => $apiSystem->id,
        'symbol_id' => $symbol->id,
        'direction' => $direction,
        'indicators_timeframe' => '1h',
        'indicators_values' => $indicatorsValues,
    ]);
}

it('persists all seven levels from a TAAPI-shaped wrapped-array payload', function (): void {
    // This is the exact shape TAAPI returns — array of one candle
    // window, each entry carrying the seven levels.
    $es = buildExchangeSymbolForPivotTest(
        direction: 'SHORT',
        indicatorsValues: [
            'pivotpoints' => [
                'result' => [[
                    'r3' => 0.0450,
                    'r2' => 0.0440,
                    'r1' => 0.0430,
                    'p' => 0.0420,
                    's1' => 0.0410,
                    's2' => 0.0400,
                    's3' => 0.0390,
                ]],
            ],
        ],
    );

    $result = (new QueryAndStoreSupportAndResistanceJob($es->id))->compute();

    expect($result['status'] ?? null)->toBe('stored');

    $es->refresh();
    expect((float) $es->pivot_r3)->toBe(0.045);
    expect((float) $es->pivot_r1)->toBe(0.043);
    expect((float) $es->pivot_p)->toBe(0.042);
    expect((float) $es->pivot_s1)->toBe(0.041);
    expect((float) $es->pivot_s3)->toBe(0.039);
    expect($es->pivot_synced_at)->not->toBeNull();
});

it('also accepts a flat payload shape (defensive compatibility)', function (): void {
    $es = buildExchangeSymbolForPivotTest(
        direction: 'LONG',
        indicatorsValues: [
            'pivotpoints' => [
                'result' => [
                    'r3' => 100.3, 'r2' => 100.2, 'r1' => 100.1,
                    'p' => 100.0,
                    's1' => 99.9, 's2' => 99.8, 's3' => 99.7,
                ],
            ],
        ],
    );

    $result = (new QueryAndStoreSupportAndResistanceJob($es->id))->compute();
    expect($result['status'] ?? null)->toBe('stored');

    $es->refresh();
    expect((float) $es->pivot_r1)->toBe(100.1);
    expect((float) $es->pivot_s1)->toBe(99.9);
});

it('skips with a named reason when indicators_values has no pivotpoints at all', function (): void {
    $es = buildExchangeSymbolForPivotTest(
        direction: 'LONG',
        indicatorsValues: ['adx' => ['result' => ['value' => [25]]]], // no pivotpoints key
    );

    $result = (new QueryAndStoreSupportAndResistanceJob($es->id))->compute();

    expect($result['status'])->toBe('skipped');
    expect($result['reason'])->toBe('pivotpoints_not_present_in_indicators_values');

    $es->refresh();
    expect($es->pivot_r1)->toBeNull();
    expect($es->pivot_synced_at)->toBeNull();
});

it('skips with a named reason when the payload shape is unrecognised (neither wrapped nor flat)', function (): void {
    $es = buildExchangeSymbolForPivotTest(
        direction: 'LONG',
        indicatorsValues: [
            'pivotpoints' => [
                // No r1/s1 keys anywhere — TAAPI contract breach
                'result' => ['unexpected' => 'shape'],
            ],
        ],
    );

    $result = (new QueryAndStoreSupportAndResistanceJob($es->id))->compute();

    expect($result['status'])->toBe('skipped');
    expect($result['reason'])->toBe('pivotpoints_payload_shape_unrecognised');

    // Crucially: pivot_synced_at must NOT be stamped on a skip.
    // The original bug stamped it even when levels were all null,
    // producing the half-populated zombie-state row.
    $es->refresh();
    expect($es->pivot_synced_at)->toBeNull();
    expect($es->pivot_r1)->toBeNull();
});

it('skips when direction has been cleared between scheduling and execution', function (): void {
    $es = buildExchangeSymbolForPivotTest(
        direction: null, // direction cleared — common when ConfirmPriceAlignment runs first
        indicatorsValues: [
            'pivotpoints' => ['result' => [['r1' => 50, 's1' => 40, 'p' => 45, 'r3' => 55, 's3' => 35]]],
        ],
    );

    $result = (new QueryAndStoreSupportAndResistanceJob($es->id))->compute();

    expect($result['status'])->toBe('skipped');
    expect($result['reason'])->toBe('direction_cleared');

    $es->refresh();
    expect($es->pivot_synced_at)->toBeNull();
    expect($es->pivot_r1)->toBeNull();
});

it('ConfirmPriceAlignmentWithDirectionJob nulls pivot columns on direction invalidation', function (): void {
    $source = file_get_contents(
        (new ReflectionClass(Kraite\Core\Jobs\Atomic\ExchangeSymbol\ConfirmPriceAlignmentWithDirectionJob::class))
            ->getFileName()
    );

    // Two invalidation paths: missing indicator history + price trend
    // misalignment. Both must null the pivot columns alongside direction
    // / indicators_values / indicators_timeframe — otherwise stale
    // pivots survive the direction clear and the selection-time S/R
    // multiplier reads zombie levels against a live mark_price.
    $occurrences = mb_substr_count($source, "'pivot_r1' => null");

    expect($occurrences)->toBeGreaterThanOrEqual(
        2,
        'Both invalidation paths in ConfirmPriceAlignmentWithDirectionJob '
        .'must null the pivot columns. Current count of '
        ."\"'pivot_r1' => null\" occurrences: {$occurrences}"
    );
});
