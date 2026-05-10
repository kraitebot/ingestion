<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\User;

final class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedSysadmin();

        if (app()->environment('local')) {
            $this->seedKarineTrader();
        }
    }

    private function seedSysadmin(): void
    {
        User::updateOrCreate(
            ['email' => config('kraite.admin_user_email', 'bruno@kraite.com')],
            [
                'name' => config('kraite.admin_user_name', 'Bruno Falcao'),
                'password' => bcrypt(config()->string('kraite.admin_user_password', 'password')),
                'is_active' => true,
                'is_admin' => true,
            ]
        );
    }

    private function seedKarineTrader(): void
    {
        $binance = ApiSystem::where('canonical', 'binance')->first();

        if (! $binance) {
            return;
        }

        $karine = User::updateOrCreate(
            ['email' => 'kaesnault@outlook.com'],
            [
                'name' => 'Karine Esnault',
                'password' => bcrypt('password'),
                'is_active' => true,
                'is_admin' => false,
                'notification_channels' => ['mail'],
            ]
        );

        Account::updateOrCreate(
            [
                'user_id' => $karine->id,
                'api_system_id' => $binance->id,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Karine Binance Account',
                'portfolio_quote' => 'USDT',
                'trading_quote' => 'USDT',
                'trade_configuration_id' => 1,
                'binance_api_key' => config('kraite-ingestion.karine.binance_api_key'),
                'binance_api_secret' => config('kraite-ingestion.karine.binance_api_secret'),
                'margin_percentage_long' => '5.00',
                'margin_percentage_short' => '5.00',
                'total_positions_long' => 6,
                'total_positions_short' => 6,
                'is_active' => true,
                'on_hedge_mode' => false,
            ]
        );
    }
}
