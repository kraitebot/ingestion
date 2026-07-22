<?php

declare(strict_types=1);

use Kraite\Core\Database\Seeders\KraiteSeeder;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Kraite;
use Kraite\Core\Models\User;

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
