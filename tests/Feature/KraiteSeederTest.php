<?php

declare(strict_types=1);

use Kraite\Core\Database\Seeders\KraiteSeeder;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\ExchangeSymbol;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\User;

it('removes the unsupported 12h timeframe and clears only conclusions produced from it', function (): void {
    $engine = Kraite::findOrFail(1);
    $engine->update([
        'email' => 'timeframe-migration@example.com',
        'timeframes' => ['1h', '4h', '12h', '1d'],
    ]);

    $twelveHourSymbol = ExchangeSymbol::factory()->create([
        'direction' => 'SHORT',
        'indicators_timeframe' => '12h',
        'indicators_values' => ['candle-comparison' => 'SHORT'],
        'has_invalid_indicator_direction' => true,
        'has_early_direction_change' => true,
        'has_price_trend_misalignment' => true,
    ]);
    $fourHourSymbol = ExchangeSymbol::factory()->create([
        'direction' => 'LONG',
        'indicators_timeframe' => '4h',
        'indicators_values' => ['candle-comparison' => 'LONG'],
        'has_invalid_indicator_direction' => true,
        'has_early_direction_change' => true,
        'has_price_trend_misalignment' => true,
    ]);

    Illuminate\Support\Once::flush();

    expect(Kraite::timeframes())->toBe(['1h', '4h', '12h', '1d']);

    $migration = require dirname((new ReflectionClass(KraiteSeeder::class))->getFileName()).'/../migrations/2026_08_03_105535_remove_unsupported_12h_timeframe_from_kraite.php';
    $migration->up();
    Illuminate\Support\Once::flush();
    $engine->refresh();
    $twelveHourSymbol->refresh();
    $fourHourSymbol->refresh();

    expect(Kraite::timeframes())->toBe(['1h', '4h', '1d'])
        ->and($engine->email)->toBe('timeframe-migration@example.com')
        ->and($twelveHourSymbol->direction)->toBeNull()
        ->and($twelveHourSymbol->indicators_timeframe)->toBeNull()
        ->and($twelveHourSymbol->indicators_values)->toBeNull()
        ->and($twelveHourSymbol->has_invalid_indicator_direction)->toBeFalse()
        ->and($twelveHourSymbol->has_early_direction_change)->toBeFalse()
        ->and($twelveHourSymbol->has_price_trend_misalignment)->toBeFalse()
        ->and($fourHourSymbol->direction)->toBe('LONG')
        ->and($fourHourSymbol->indicators_timeframe)->toBe('4h')
        ->and($fourHourSymbol->indicators_values)->toBe(['candle-comparison' => 'LONG'])
        ->and($fourHourSymbol->has_invalid_indicator_direction)->toBeTrue()
        ->and($fourHourSymbol->has_early_direction_change)->toBeTrue()
        ->and($fourHourSymbol->has_price_trend_misalignment)->toBeTrue();

    $migration->down();
    Illuminate\Support\Once::flush();
    $engine->refresh();

    expect(Kraite::timeframes())->toBe(['1h', '4h', '12h', '1d'])
        ->and($engine->email)->toBe('timeframe-migration@example.com');
});

it('seeds the supported conclusion timeframe ladder without 12h', function (): void {
    $engine = Kraite::findOrFail(1);
    $engine->update(['timeframes' => ['1h', '4h', '12h', '1d']]);
    Illuminate\Support\Once::flush();

    expect(Kraite::timeframes())->toBe(['1h', '4h', '12h', '1d']);

    app(KraiteSeeder::class)->seedKraite();
    Illuminate\Support\Once::flush();

    expect(Kraite::timeframes())->toBe(['1h', '4h', '1d']);
});

it('seeds the resend key into the kraite credentials row', function (): void {
    config(['services.resend.key' => 're_test_seeded']);

    $engine = Kraite::findOrFail(1);
    $engine->resend_api_key = null;
    $engine->save();

    app(KraiteSeeder::class)->migrateKraiteCredentials();

    expect(Kraite::findOrFail(1)->resend_api_key)->toBe('re_test_seeded');
});

it('does not clear an existing resend key when no configured key is available', function (): void {
    config(['services.resend.key' => null]);

    $engine = Kraite::findOrFail(1);
    $engine->resend_api_key = 're_existing_seeded';
    $engine->save();

    app(KraiteSeeder::class)->migrateKraiteCredentials();

    expect(Kraite::findOrFail(1)->resend_api_key)->toBe('re_existing_seeded');
});

it('creates only the configured sysadmin when the production database is seeded', function (): void {
    $previousEnvironment = app()->environment();
    $previousAdminConfiguration = [
        'kraite.admin_user_name' => config('kraite.admin_user_name'),
        'kraite.admin_user_email' => config('kraite.admin_user_email'),
        'kraite.admin_user_password' => config('kraite.admin_user_password'),
    ];

    config([
        'kraite.admin_user_name' => 'Kraite Sysadmin',
        'kraite.admin_user_email' => 'sysadmin@example.com',
        'kraite.admin_user_password' => 'test-password',
    ]);

    expect(User::query()->exists())->toBeFalse()
        ->and(Account::query()->exists())->toBeFalse();

    app()->instance('env', 'production');

    try {
        app(Database\Seeders\DatabaseSeeder::class)->run();
        app(Database\Seeders\DatabaseSeeder::class)->run();

        $users = User::query()
            ->orderBy('email')
            ->get(['email', 'is_admin', 'is_active', 'status'])
            ->map->only(['email', 'is_admin', 'is_active', 'status'])
            ->all();

        expect($users)->toBe([[
            'email' => config('kraite.admin_user_email'),
            'is_admin' => true,
            'is_active' => true,
            'status' => 'active',
        ]])
            ->and(Account::query()->exists())->toBeFalse()
            ->and(ApiSystem::activeExchange()->pluck('canonical')->all())->toBe(['binance']);
    } finally {
        app()->instance('env', $previousEnvironment);
        config($previousAdminConfiguration);
    }
});
