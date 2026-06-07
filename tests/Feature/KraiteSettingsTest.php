<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Trading\Kraite as Engine;

/**
 * Runtime config promoted onto the kraite singleton. Every accessor reads
 * the singleton column when set and falls back to config() when NULL —
 * except canTrade(), a normally-open master kill where NULL = allowed
 * (so shipping the gate never halts live trading).
 */

// ── can_trade master kill ──────────────────────────────────────────────

it('treats a null can_trade as trading allowed (normally-open master kill)', function (): void {
    Kraite::query()->update(['can_trade' => null]);

    expect(Kraite::canTrade())->toBeTrue();
});

it('suspends trading only when can_trade is explicitly false', function (): void {
    Kraite::query()->update(['can_trade' => false]);
    expect(Kraite::canTrade())->toBeFalse();

    Kraite::query()->update(['can_trade' => true]);
    expect(Kraite::canTrade())->toBeTrue();
});

it('blocks canOpenPositions when the master kill is engaged', function (): void {
    $account = Account::factory()->create();
    Kraite::query()->update(['allow_opening_positions' => true, 'can_trade' => false]);

    expect(Engine::withAccount($account)->canOpenPositions())->toBeFalse();
});

it('allows canOpenPositions when can_trade is null and other gates pass', function (): void {
    $account = Account::factory()->create();
    Kraite::query()->update(['allow_opening_positions' => true, 'can_trade' => null]);

    expect(Engine::withAccount($account)->canOpenPositions())->toBeTrue();
});

// ── notifications_enabled (global tier) ─────────────────────────────────

it('reads notifications_enabled from the singleton, else config', function (): void {
    Kraite::query()->update(['notifications_enabled' => null]);
    Config::set('kraite.notifications_enabled', true);
    expect(Kraite::notificationsEnabled())->toBeTrue();

    Kraite::query()->update(['notifications_enabled' => false]);
    expect(Kraite::notificationsEnabled())->toBeFalse();
});

// ── td_correlation_type ─────────────────────────────────────────────────

it('reads correlation type from the singleton, else config', function (): void {
    Kraite::query()->update(['td_correlation_type' => null]);
    Config::set('kraite.token_discovery.correlation_type', 'pearson');
    expect(Kraite::correlationType())->toBe('pearson');

    Kraite::query()->update(['td_correlation_type' => 'spearman']);
    expect(Kraite::correlationType())->toBe('spearman');
});

// ── corr_enabled / elast_enabled compute switches ───────────────────────

it('reads correlation computation flag from the singleton, else config', function (): void {
    Kraite::query()->update(['corr_enabled' => null]);
    Config::set('kraite.correlation.enabled', true);
    expect(Kraite::correlationComputationEnabled())->toBeTrue();

    Kraite::query()->update(['corr_enabled' => false]);
    expect(Kraite::correlationComputationEnabled())->toBeFalse();
});

it('reads elasticity computation flag from the singleton, else config', function (): void {
    Kraite::query()->update(['elast_enabled' => null]);
    Config::set('kraite.elasticity.enabled', false);
    expect(Kraite::elasticityComputationEnabled())->toBeFalse();

    Kraite::query()->update(['elast_enabled' => true]);
    expect(Kraite::elasticityComputationEnabled())->toBeTrue();
});

// ── trail_retention_hours / slow_query_threshold_ms ─────────────────────

it('reads trail retention hours from the singleton, else config', function (): void {
    Kraite::query()->update(['trail_retention_hours' => null]);
    Config::set('kraite.positions.trail_retention_hours', 24);
    expect(Kraite::trailRetentionHours())->toBe(24);

    Kraite::query()->update(['trail_retention_hours' => 0]);
    expect(Kraite::trailRetentionHours())->toBe(0);
});
