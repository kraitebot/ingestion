<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\User;
use NotificationChannels\Pushover\PushoverChannel;

final class BusinessSeeder extends Seeder
{
    public function run(): void
    {
        // Testing keeps the minimal fixture shape: Karine only.
        if (app()->environment('testing')) {
            $this->seedKarineTrader();

            return;
        }

        // Local gets BOTH traders: Karine as the disabled smoke fixture,
        // and Bruno's real Binance account so local trading smoke tests
        // run against the same account shape production uses (gates stay
        // closed until the operator flips them).
        if (app()->environment('local')) {
            $this->seedKarineTrader();
        }

        $this->seedBrunoNidavellirTrader();
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
                'status' => 'active',
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
                'can_trade' => false,
                'is_active' => false,
                'on_hedge_mode' => false,
            ]
        );
    }

    private function seedBrunoNidavellirTrader(): void
    {
        $binance = ApiSystem::where('canonical', 'binance')->first();

        if (! $binance) {
            return;
        }

        $bruno = User::updateOrCreate(
            ['email' => 'bruno@nidavellir.trade'],
            [
                'name' => 'Bruno Falcao',
                'password' => bcrypt('password'),
                'status' => 'active',
                'is_active' => true,
                'is_admin' => false,
                // Full channel class — AlertNotification::via() returns this
                // array verbatim to Laravel's channel manager, which resolves
                // class names, not the bare string 'pushover'.
                'notification_channels' => ['mail', PushoverChannel::class],
                'pushover_key' => config('kraite-ingestion.bruno_nidavellir.pushover_key'),
            ]
        );

        Account::updateOrCreate(
            [
                'user_id' => $bruno->id,
                'api_system_id' => $binance->id,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Bruno Falcao Binance Account',
                'portfolio_quote' => 'USDT',
                'trading_quote' => 'USDT',
                'trade_configuration_id' => 1,
                'binance_api_key' => config('kraite-ingestion.bruno_nidavellir.binance_api_key'),
                'binance_api_secret' => config('kraite-ingestion.bruno_nidavellir.binance_api_secret'),
                'margin_percentage_long' => '5.00',
                'margin_percentage_short' => '5.00',
                'total_positions_long' => 6,
                'total_positions_short' => 6,
                'can_trade' => false,
                'is_active' => false,
                'on_hedge_mode' => false,
            ]
        );
    }
}
