<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Kraite\Core\Jobs\Models\MarketRegime\DetectMarketShockJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\MarketPriceSample;

/**
 * Live-window path of DetectMarketShockJob (core 1.63.0): rolling
 * 1-minute mark-price samples, offsets 15/60, 2-consecutive-tick
 * persistence before arming, kill switch, gap guard, stale-mark
 * sampling guard. The kline fallback path keeps its own pre-existing
 * suite (DetectMarketShockJobTest) untouched.
 */
function seedLiveSeries(string $token, callable $closeForMinute, int $count = 61): void
{
    $start = CarbonImmutable::now()->subMinutes($count - 1);
    for ($i = 0; $i < $count; $i++) {
        MarketPriceSample::create([
            'token' => $token,
            'price' => (string) $closeForMinute($i),
            'sampled_at' => $start->addMinutes($i),
        ]);
    }
}

function seedBreachingBasket(): void
{
    // BTC flat, then -4% across the final 15 minutes → btc_15m fires.
    seedLiveSeries('BTC', function (int $i): float {
        if ($i < 46) {
            return 50000.0;
        }

        return 50000.0 * (1 - 0.04 * (($i - 45) / 15));
    });

    foreach (['ETH' => 3000.0, 'SOL' => 100.0, 'BNB' => 600.0, 'XRP' => 0.5] as $token => $base) {
        seedLiveSeries($token, static fn (int $i): float => $base);
    }
}

beforeEach(function (): void {
    $this->binance = ApiSystem::firstOrCreate(
        ['canonical' => 'binance'],
        ['is_exchange' => true, 'name' => 'Binance', 'recvwindow_margin' => 1000]
    );

    Kraite::find(1)->updateSaving([
        'bscs_cooldown_until' => null,
        'bscs_score' => 20,
        'bscs_band' => 'calm',
        'bscs_synced_at' => now(),
        'bscs_block_active' => false,
    ]);

    Cache::forget('market_shock_live_breach_run');
});

it('arms only on the second consecutive breaching tick (persistence guard)', function (): void {
    seedBreachingBasket();

    $first = (new DetectMarketShockJob)->compute();

    expect($first['live_status'])->toBe('breach_pending_1_of_2')
        ->and($first['action'])->not->toBe('cooldown_armed')
        ->and(Kraite::find(1)->refresh()->bscs_cooldown_until)->toBeNull();

    $second = (new DetectMarketShockJob)->compute();
    $kraite = Kraite::find(1)->refresh();

    expect($second['action'])->toBe('cooldown_armed')
        ->and($second['source'])->toBe('live_window')
        ->and($second['rules_triggered'])->toContain('btc_15m')
        ->and($kraite->bscs_cooldown_until)->not->toBeNull()
        ->and($kraite->bscs_cooldown_until->isFuture())->toBeTrue();
});

it('does not evaluate the live path when the kill switch is off', function (): void {
    config(['kraite.market_regime.shock.live_window.enabled' => false]);
    seedBreachingBasket();

    $result = (new DetectMarketShockJob)->compute();

    expect($result['live_status'])->toBe('disabled')
        ->and(Kraite::find(1)->refresh()->bscs_cooldown_until)->toBeNull();
});

it('skips the live evaluation when the series has a gap', function (): void {
    // 61 BTC samples but with a 5-minute hole in the middle.
    $start = CarbonImmutable::now()->subMinutes(65);
    for ($i = 0; $i < 61; $i++) {
        $offset = $i < 30 ? $i : $i + 5;
        MarketPriceSample::create([
            'token' => 'BTC',
            'price' => '50000',
            'sampled_at' => $start->addMinutes($offset),
        ]);
    }

    $result = (new DetectMarketShockJob)->compute();

    expect($result['live_status'])->toBe('insufficient_series');
});

it('a calm tick resets the breach run counter', function (): void {
    foreach (['BTC' => 50000.0, 'ETH' => 3000.0, 'SOL' => 100.0, 'BNB' => 600.0, 'XRP' => 0.5] as $token => $base) {
        seedLiveSeries($token, static fn (int $i): float => $base);
    }

    Cache::put('market_shock_live_breach_run', 1, now()->addMinutes(10));

    $result = (new DetectMarketShockJob)->compute();

    expect($result['live_status'])->toBe('no_fire')
        ->and(Cache::get('market_shock_live_breach_run'))->toBeNull();
});

it('samples fresh marks and skips stale ones', function (): void {
    ExchangeSymbol::factory()->create([
        'token' => 'BTC', 'quote' => 'USDT', 'api_system_id' => $this->binance->id,
        'mark_price' => '50000', 'mark_price_synced_at' => now()->subSeconds(5),
    ]);
    ExchangeSymbol::factory()->create([
        'token' => 'ETH', 'quote' => 'USDT', 'api_system_id' => $this->binance->id,
        'mark_price' => '3000', 'mark_price_synced_at' => now()->subSeconds(300),
    ]);

    (new DetectMarketShockJob)->sampleLiveMarks();

    expect(MarketPriceSample::where('token', 'BTC')->count())->toBe(1)
        ->and(MarketPriceSample::where('token', 'ETH')->count())->toBe(0);
});

it('prunes samples older than the retention window', function (): void {
    MarketPriceSample::create(['token' => 'BTC', 'price' => '50000', 'sampled_at' => now()->subHours(4)]);
    MarketPriceSample::create(['token' => 'BTC', 'price' => '50000', 'sampled_at' => now()->subMinutes(10)]);

    (new DetectMarketShockJob)->pruneLiveSamples();

    expect(MarketPriceSample::count())->toBe(1);
});
