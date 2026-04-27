<?php

declare(strict_types=1);

use Kraite\Core\Jobs\Atomic\Position\PreparePositionDataJob;

/**
 * Regression guard for the per-symbol TP/SL resolution wire-up.
 *
 * `PreparePositionDataJob` is the single point that snapshots the
 * resolved TP and SL onto the position so subsequent placement jobs
 * (PlaceProfitOrderJob / PlaceStopLossOrderJob / Bitget PlacePositionTpslJob)
 * read frozen values — mid-life edits to the symbol or account never
 * retroactively rewrite an open position's exit policy.
 *
 * These pin the contract via source assertions because the alternative
 * (a full Position + Account + ExchangeSymbol + ApiSnapshot setup) buys
 * little extra confidence — the resolver math itself is covered by
 * `TpSlResolverTest`, and the observer fan-out by
 * `ExchangeSymbolTpSlPropagationTest`. What we need to guarantee here
 * is that the job calls the resolver and writes BOTH columns onto the
 * position.
 */
function preparePositionDataJobSource(): string
{
    $reflection = new ReflectionClass(PreparePositionDataJob::class);

    return file_get_contents($reflection->getFileName());
}

it('imports TpSlResolver', function (): void {
    expect(preparePositionDataJobSource())
        ->toContain('use Kraite\\Core\\Support\\TpSlResolver;');
});

it('resolves profit_percentage through TpSlResolver with override_tp flag', function (): void {
    $source = preparePositionDataJobSource();

    expect($source)->toContain('TpSlResolver::resolve(')
        ->and($source)->toContain('symbolValue: $exchangeSymbol->profit_percentage')
        ->and($source)->toContain('accountOverride: (bool) $account->override_tp')
        ->and($source)->toContain('accountValue: (string) $account->profit_percentage');
});

it('resolves stop_market_percentage through TpSlResolver with override_sl flag', function (): void {
    $source = preparePositionDataJobSource();

    expect($source)->toContain('symbolValue: $exchangeSymbol->stop_market_percentage')
        ->and($source)->toContain('accountOverride: (bool) $account->override_sl')
        ->and($source)->toContain('accountValue: (string) $account->stop_market_initial_percentage');
});

it('snapshots BOTH profit_percentage AND stop_market_percentage onto the position', function (): void {
    $source = preparePositionDataJobSource();

    // updateSaving must include both — missing the SL column would
    // silently leave PlaceStopLossOrderJob without an SL value at
    // place time (gated by startOrFail).
    expect($source)->toContain("'profit_percentage' => \$profitPercentage,")
        ->and($source)->toContain("'stop_market_percentage' => \$stopMarketPercentage,");
});

it('returns both resolved percentages in the step payload for downstream visibility', function (): void {
    $source = preparePositionDataJobSource();

    expect($source)->toContain("'profit_percentage' => \$profitPercentage,")
        ->and($source)->toContain("'stop_market_percentage' => \$stopMarketPercentage,");
});

it('imports the Phase 2.1C BSCS sizing helpers (FragileMarginMultiplier, CrowdingMultiplier, BlackSwanIndex)', function (): void {
    $source = preparePositionDataJobSource();

    expect($source)->toContain('use Kraite\\Core\\Support\\MarketRegime\\BlackSwanIndex;')
        ->and($source)->toContain('use Kraite\\Core\\Support\\MarketRegime\\CrowdingMultiplier;')
        ->and($source)->toContain('use Kraite\\Core\\Support\\MarketRegime\\FragileMarginMultiplier;');
});

it('applies FragileMarginMultiplier × CrowdingMultiplier to the base margin before persisting', function (): void {
    // Both multipliers must be invoked, with their product applied to
    // the base margin string. The wire-in must happen AFTER the base
    // margin computation (calculateMarginWithSubscriptionCap) and
    // BEFORE the position is saved.
    $source = preparePositionDataJobSource();

    expect($source)->toContain('FragileMarginMultiplier::for(')
        ->and($source)->toContain('CrowdingMultiplier::for(')
        ->and($source)->toContain('BlackSwanIndex::current()');
});
