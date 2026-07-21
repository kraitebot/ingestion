<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Kraite\Core\Enums\RegimeBand;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\MarketRegimeSnapshot;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\MarketRegime\Bscs;

function setBscsFacadeState(?int $score, ?Carbon\CarbonInterface $cooldownUntil = null): void
{
    Kraite::findOrFail(1)->updateSaving([
        'bscs_score' => $score,
        'bscs_band' => $score === null ? null : RegimeBand::fromScore($score)->value,
        'bscs_synced_at' => $score === null ? null : now(),
        'bscs_block_active' => $cooldownUntil !== null,
        'bscs_block_threshold' => 80,
        'bscs_freshness_max_seconds' => 6900,
        'bscs_cooldown_until' => $cooldownUntil,
    ]);
}

function createBscsFacadeAccount(int $maximumLongs = 6, int $maximumShorts = 6): Account
{
    return Account::factory()->create([
        'name' => 'bscs-facade-'.fake()->uuid(),
        'total_positions_long' => $maximumLongs,
        'total_positions_short' => $maximumShorts,
    ]);
}

it('returns account-specific saved and effective position caps through one BSCS entry point', function (
    ?int $score,
    int $effective,
    int $ratioPercent,
): void {
    setBscsFacadeState($score);
    $account = createBscsFacadeAccount();

    $caps = Bscs::forAccount($account)->positions()->max();

    expect($caps->toArray())->toBe([
        'long' => ['effective' => $effective, 'maximum' => 6],
        'short' => ['effective' => $effective, 'maximum' => 6],
        'ratio_percent' => $ratioPercent,
    ])->and([$account->total_positions_long, $account->total_positions_short])->toBe([6, 6]);
})->with([
    'before first compute' => [null, 6, 100],
    'calm upper boundary' => [39, 6, 100],
    'elevated lower boundary' => [40, 4, 75],
    'fragile lower boundary' => [60, 3, 50],
    'critical lower boundary' => [80, 0, 0],
]);

it('calculates directional availability against the larger exchange or database count', function (): void {
    setBscsFacadeState(50);
    $account = createBscsFacadeAccount();
    $positions = Bscs::forAccount($account)->positions();

    $availability = $positions->available(
        exchangeLongs: 1,
        exchangeShorts: 4,
        databaseLongs: 3,
        databaseShorts: 2,
    );

    expect($availability->toArray())->toBe([
        'long' => ['available' => 1, 'current' => 3, 'maximum' => 4],
        'short' => ['available' => 0, 'current' => 4, 'maximum' => 4],
    ]);
});

it('exposes BSCS opening and leverage policies from the same loaded state', function (): void {
    setBscsFacadeState(70, now()->addHours(2));
    $account = createBscsFacadeAccount();
    $bscs = Bscs::forAccount($account);

    expect($bscs->opening()->allowsNewPositions())->toBeFalse()
        ->and($bscs->leverage()->adjust(9)->toArray())->toBe([
            'base' => 9,
            'ratio' => 0.5,
            'effective' => 4,
            'bscs_score' => 70,
        ]);
});

it('combines fragile-regime and directional-crowding margin adjustments behind BSCS', function (): void {
    setBscsFacadeState(79);
    $account = createBscsFacadeAccount();
    $crowdedLong = Position::factory()->create([
        'account_id' => $account->id,
        'direction' => 'LONG',
        'status' => 'active',
        'margin' => '100.00000000',
        'leverage' => 10,
    ]);

    $adjustment = Bscs::forAccount($account)->margin()->adjust('100.00000000', 'LONG');

    expect($adjustment->toArray())->toBe([
        'base' => '100.00000000',
        'fragile_multiplier' => 0.5,
        'crowding_multiplier' => 0.5,
        'combined_multiplier' => 0.25,
        'effective' => '25.0000000000000000',
        'bscs_score' => 79,
    ])->and($crowdedLong->refresh()->status)->toBe('active');
});

it('loads trading state once and defers the forensic snapshot query until details are requested', function (): void {
    setBscsFacadeState(50);
    $account = createBscsFacadeAccount();

    MarketRegimeSnapshot::create([
        'computed_at' => now()->startOfHour(),
        'bscs_score' => 50,
        'bscs_band' => 'elevated',
        'vol_expansion_value' => '1.1000',
        'vol_expansion_fired' => false,
        'range_blowout_value' => '1.2000',
        'range_blowout_fired' => false,
        'corr_regime_value' => '0.5000',
        'corr_regime_fired' => false,
        'rejection_pct_value' => '-2.00',
        'rejection_pct_fired' => false,
        'fut_vol_value' => '1.0000',
        'fut_vol_fired' => false,
        'btc_close' => '100000.00000000',
        'inputs_meta' => [],
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $bscs = Bscs::forAccount($account);
    $bscs->opening()->allowsNewPositions();
    $bscs->positions()->max();
    $bscs->leverage()->adjust(10);

    $tradingQueries = collect(DB::getQueryLog());

    expect($tradingQueries->filter(function (array $query): bool {
        return str_contains($query['query'], 'from `kraite`');
    }))->toHaveCount(1)
        ->and($tradingQueries->filter(function (array $query): bool {
            return str_contains($query['query'], 'market_regime_snapshots');
        }))->toHaveCount(0);

    expect($bscs->details()->latestSnapshot()?->bscs_score)->toBe(50);

    $allQueries = collect(DB::getQueryLog());

    expect($allQueries->filter(function (array $query): bool {
        return str_contains($query['query'], 'market_regime_snapshots');
    }))->toHaveCount(1);
});

it('loads fresh state for each context in long-lived workers', function (): void {
    $account = createBscsFacadeAccount();
    setBscsFacadeState(40);
    $firstContext = Bscs::forAccount($account);

    setBscsFacadeState(60);
    $secondContext = Bscs::forAccount($account);

    expect($firstContext->state()->score())->toBe(40)
        ->and($secondContext->state()->score())->toBe(60);
});
