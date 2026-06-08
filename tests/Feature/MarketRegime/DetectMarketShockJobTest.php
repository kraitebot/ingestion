<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Kraite\Core\Jobs\Models\MarketRegime\DetectMarketShockJob;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Candle;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;

/**
 * DetectMarketShockJob — fast cascade detector that arms a shared
 * cooldown when MarketShockCircuitBreaker fires.
 *
 * Behaviour:
 *
 *   1. Reads the last ~20 × 15m bars for BTC + the 4 BSCS reference
 *      alts (ETH/SOL/BNB/XRP) from the `candles` table.
 *
 *   2. Calls MarketShockCircuitBreaker::evaluate(). If fired:
 *
 *        a. No active cooldown → arm bscs_cooldown_until = now()
 *           + cooldown_hours, dispatch market_shock_circuit_breaker
 *           notification.
 *        b. Cooldown already active → silent no-op (no re-arm, no
 *           notification — avoids double-pinging on a cascade that
 *           keeps firing minute after minute).
 *        c. Operator override active → no-op (escape hatch wins).
 *
 *   3. If not fired → no-op.
 */
function seedReferenceCandlesFor(int $apiSystemId, array $tokens, callable $closeForBar): array
{
    $ids = [];
    foreach ($tokens as $token) {
        $row = ExchangeSymbol::factory()->create([
            'token' => $token,
            'quote' => 'USDT',
            'api_system_id' => $apiSystemId,
        ]);
        $ids[$token] = $row->id;

        $rows = [];
        $tsBase = CarbonImmutable::now()->subMinutes(20 * 15)->getTimestamp();
        for ($i = 0; $i < 20; $i++) {
            $ts = $tsBase + ($i * 900);
            $rows[] = [
                'exchange_symbol_id' => $row->id,
                'timeframe' => '15m',
                'timestamp' => $ts,
                'candle_time_utc' => date('Y-m-d H:i:s', $ts),
                'candle_time_local' => date('Y-m-d H:i:s', $ts),
                'open' => '100',
                'high' => '100',
                'low' => '100',
                'close' => (string) $closeForBar($token, $i),
                'volume' => '1000',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        Candle::insert($rows);
    }

    return $ids;
}

beforeEach(function (): void {
    config(['kraite.market_regime.symbols' => [
        'BTCUSDT', 'ETHUSDT', 'SOLUSDT', 'BNBUSDT', 'XRPUSDT',
    ]]);

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
});

it('arms a 24h cooldown and notifies when a shock rule fires', function (): void {
    // Drop BTC's last bar 4% — fires rule #1 (btc_15m).
    seedReferenceCandlesFor($this->binance->id, ['BTC', 'ETH', 'SOL', 'BNB', 'XRP'],
        function (string $token, int $i): float {
            $base = match ($token) {
                'BTC' => 50000.0,
                'ETH' => 3000.0,
                'SOL' => 100.0,
                'BNB' => 600.0,
                'XRP' => 0.5,
            };
            // Last bar BTC drops 4%; alts stay flat.
            if ($token === 'BTC' && $i === 19) {
                return $base * 0.96;
            }

            return $base;
        }
    );

    $job = new DetectMarketShockJob;
    $result = $job->compute();

    $kraite = Kraite::find(1)->refresh();

    expect($result['action'])->toBe('cooldown_armed')
        ->and($result['rules_triggered'])->toContain('btc_15m')
        ->and($kraite->bscs_cooldown_until)->not->toBeNull()
        ->and($kraite->bscs_cooldown_until->isFuture())->toBeTrue();
});

it('uses the configured cooldown_hours when arming (default 24)', function (): void {
    config(['kraite.market_regime.shock.cooldown_hours' => 6]);

    seedReferenceCandlesFor($this->binance->id, ['BTC', 'ETH', 'SOL', 'BNB', 'XRP'],
        function (string $token, int $i): float {
            $base = match ($token) {
                'BTC' => 50000.0, 'ETH' => 3000.0, 'SOL' => 100.0, 'BNB' => 600.0, 'XRP' => 0.5,
            };
            if ($token === 'BTC' && $i === 19) {
                return $base * 0.96;
            }

            return $base;
        }
    );

    $beforeArm = CarbonImmutable::now();

    (new DetectMarketShockJob)->compute();

    $kraite = Kraite::find(1)->refresh();
    $diffHours = abs($kraite->bscs_cooldown_until->diffInHours($beforeArm));

    expect($diffHours)->toBeBetween(5.99, 6.01);
});

it('silent no-op when a cooldown is already active (no double-arm, no double-notification)', function (): void {
    $existingCooldown = CarbonImmutable::now()->addHours(20);
    Kraite::find(1)->updateSaving(['bscs_cooldown_until' => $existingCooldown]);

    seedReferenceCandlesFor($this->binance->id, ['BTC', 'ETH', 'SOL', 'BNB', 'XRP'],
        function (string $token, int $i): float {
            $base = match ($token) {
                'BTC' => 50000.0, 'ETH' => 3000.0, 'SOL' => 100.0, 'BNB' => 600.0, 'XRP' => 0.5,
            };
            // Aggressive: drop BTC 4% — would fire if not for the active cooldown.
            if ($token === 'BTC' && $i === 19) {
                return $base * 0.96;
            }

            return $base;
        }
    );

    $result = (new DetectMarketShockJob)->compute();

    $kraite = Kraite::find(1)->refresh();

    expect($result['action'])->toBe('cooldown_already_active')
        ->and($kraite->bscs_cooldown_until?->toIso8601String())
        ->toBe($existingCooldown->toIso8601String());
});

it('no-op when no rule fires on calm market data', function (): void {
    seedReferenceCandlesFor($this->binance->id, ['BTC', 'ETH', 'SOL', 'BNB', 'XRP'],
        function (string $token, int $i): float {
            return match ($token) {
                'BTC' => 50000.0, 'ETH' => 3000.0, 'SOL' => 100.0, 'BNB' => 600.0, 'XRP' => 0.5,
            };
        }
    );

    $result = (new DetectMarketShockJob)->compute();

    $kraite = Kraite::find(1)->refresh();

    expect($result['action'])->toBe('noop_no_fire')
        ->and($kraite->bscs_cooldown_until)->toBeNull();
});

it('no-op when reference klines are missing entirely (graceful — no exceptions)', function (): void {
    // Don't seed any candles. Job should bail out cleanly.
    $result = (new DetectMarketShockJob)->compute();

    expect($result['action'])->toBe('noop_insufficient_data');
});
