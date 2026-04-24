<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\ApiSystem;
use Kraite\Core\Models\Position;
use Kraite\Core\Models\User;

final class BusinessSeeder extends Seeder
{
    /**
     * Seed business data: traders, accounts, and exchange integrations.
     */
    public function run(): void
    {
        $apiSystems = ApiSystem::all()->keyBy('canonical');

        $trader = $this->seedUser();
        $this->seedBinanceAccount($apiSystems['binance']);
        $this->updatePositionProfitPrices();
        $this->migrateAccountCredentials();
        $this->setupBybitIntegration($trader, $apiSystems['bybit']);
        $this->setupKucoinIntegration($apiSystems['kucoin']);
        $this->setupBitgetIntegration($apiSystems['bitget']);
        $this->setupBinanceOnlyIntegration($apiSystems['binance']);
        $this->cleanupAccountCredentials();
        $this->deactivateNonPrimaryAccounts();
    }

    /**
     * Seed the Binance+Bybit user/trader. Currently created inactive — the
     * live account is the admin's Binance one; this record exists mostly so
     * older fixtures keep resolving and so we can flip `is_active` back on
     * if we ever want to revive the second trader path.
     */
    private function seedUser(): User
    {
        $userData = [
            'name' => config('kraite-ingestion.traders.binance_bybit.name'),
            'email' => config('kraite-ingestion.traders.binance_bybit.email'),
            'password' => bcrypt(config('kraite-ingestion.traders.binance_bybit.password', 'password')),
            'is_active' => false,
            'is_admin' => true,
            'pushover_key' => config('kraite-ingestion.traders.binance_bybit.pushover_key'),
            'notification_channels' => ['mail', 'pushover'],
        ];

        return User::updateOrCreate(
            ['email' => $userData['email']],
            $userData
        );
    }

    /**
     * Seed the primary Binance account under the admin user. This is the
     * single live persisted account — every other account created later in
     * this seeder is deactivated by `deactivateNonPrimaryAccounts()`.
     */
    private function seedBinanceAccount(ApiSystem $binance): void
    {
        $admin = User::where('email', config('kraite.admin_user_email'))->firstOrFail();

        Account::updateOrCreate(
            [
                'user_id' => $admin->id,
                'api_system_id' => $binance->id,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Main Binance Account',
                'portfolio_quote' => 'USDT',
                'trading_quote' => 'USDT',
                'trade_configuration_id' => 1,
                'binance_api_key' => config('kraite-ingestion.traders.binance_bybit.binance_api_key'),
                'binance_api_secret' => config('kraite-ingestion.traders.binance_bybit.binance_api_secret'),
                'margin_percentage_long' => '5.00',
                'margin_percentage_short' => '5.00',
                // Open up to 6 LONG slots and 6 SHORT slots concurrently on
                // this account. The migration default is 1 per side —
                // overridden here so the main live account actually runs a
                // trading book rather than a single pair per direction.
                'total_positions_long' => 6,
                'total_positions_short' => 6,
                'is_active' => true,
            ]
        );
    }

    /**
     * Update specific positions with hardcoded profit prices.
     */
    private function updatePositionProfitPrices(): void
    {
        $positionData = [
            1072 => 3.04389988, // TONUSDT
            1073 => 0.00318842, // DEGENUSDT
            1064 => 321.68888500, // AAVEUSDT
            1063 => 0.43392483, // ARKUSDT
            1060 => 0.30980976, // SUSDT
            1061 => 0.20292457, // PNUTUSDT
            989 => 566.23485550, // BCHUSDT
            983 => 0.95447097, // ONDUSDT
            974 => 0.26890577, // SANDUSDT
            973 => 0.82540880, // ALGUSDT
            782 => 124.80333000, // LTCUSDT
            733 => 887.30647000, // BNBUSDT
        ];

        foreach ($positionData as $id => $profitPrice) {
            $position = Position::find($id);

            if ($position) {
                $position->updateSaving([
                    'first_profit_price' => $profitPrice,
                ]);
            }
        }
    }

    /**
     * Migrate exchange credentials from JSON to dedicated columns.
     */
    private function migrateAccountCredentials(): void
    {
        $account = Account::find(1);

        if ($account && isset($account->credentials['api_key'])) {
            $account->binance_api_key = $account->credentials['api_key'];
            $account->binance_api_secret = $account->credentials['api_secret'];
            $account->save();
        }
    }

    /**
     * Setup Bybit integration: account for existing trader.
     */
    private function setupBybitIntegration(User $trader, ApiSystem $bybitApiSystem): void
    {
        $existingBybitAccount = Account::where('user_id', $trader->id)
            ->where('api_system_id', $bybitApiSystem->id)
            ->first();

        if (! $existingBybitAccount) {
            Account::create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Main Bybit Account',
                'user_id' => $trader->id,
                'api_system_id' => $bybitApiSystem->id,
                'portfolio_quote' => 'USDT',
                'trading_quote' => 'USDT',
                'trade_configuration_id' => 1,
                'bybit_api_key' => config('kraite-ingestion.traders.binance_bybit.bybit_api_key'),
                'bybit_api_secret' => config('kraite-ingestion.traders.binance_bybit.bybit_api_secret'),
                'margin_percentage_long' => '5.00',
                'margin_percentage_short' => '5.00',
                'is_active' => false,
            ]);
        }
    }

    /**
     * Setup Binance-only integration: Create Binance-only user and account.
     */
    private function setupBinanceOnlyIntegration(ApiSystem $binanceApiSystem): void
    {
        $binanceEmail = config('kraite-ingestion.traders.binance_only.email');

        if (! $binanceEmail) {
            return;
        }

        $binanceUser = User::updateOrCreate(
            ['email' => $binanceEmail],
            [
                'name' => config('kraite-ingestion.traders.binance_only.name'),
                'password' => bcrypt(config('kraite-ingestion.traders.binance_only.password', 'password')),
                'is_active' => false,
                'is_admin' => false,
                'pushover_key' => config('kraite-ingestion.traders.binance_only.pushover_key'),
                'notification_channels' => ['mail', 'pushover'],
            ]
        );

        $existingBinanceAccount = Account::where('user_id', $binanceUser->id)
            ->where('api_system_id', $binanceApiSystem->id)
            ->first();

        if (! $existingBinanceAccount) {
            Account::create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Binance Only Account',
                'user_id' => $binanceUser->id,
                'api_system_id' => $binanceApiSystem->id,
                'portfolio_quote' => 'USDT',
                'trading_quote' => 'USDT',
                'trade_configuration_id' => 1,
                'binance_api_key' => config('kraite-ingestion.traders.binance_only.binance_api_key'),
                'binance_api_secret' => config('kraite-ingestion.traders.binance_only.binance_api_secret'),
                'margin_percentage_long' => '5.00',
                'margin_percentage_short' => '5.00',
                'is_active' => false,
            ]);
        }
    }

    /**
     * Setup KuCoin integration: Create KuCoin user and account.
     */
    private function setupKucoinIntegration(ApiSystem $kucoinApiSystem): void
    {
        $kucoinEmail = config('kraite-ingestion.traders.kucoin.email');

        if (! $kucoinEmail) {
            return;
        }

        $kucoinUser = User::updateOrCreate(
            ['email' => $kucoinEmail],
            [
                'name' => config('kraite-ingestion.traders.kucoin.name'),
                'password' => bcrypt(config('kraite-ingestion.traders.kucoin.password', 'password')),
                'is_active' => false,
                'is_admin' => false,
                'pushover_key' => config('kraite-ingestion.traders.kucoin.pushover_key'),
                'notification_channels' => ['mail', 'pushover'],
            ]
        );

        $existingKucoinAccount = Account::where('user_id', $kucoinUser->id)
            ->where('api_system_id', $kucoinApiSystem->id)
            ->first();

        if (! $existingKucoinAccount) {
            Account::create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Main KuCoin Account',
                'user_id' => $kucoinUser->id,
                'api_system_id' => $kucoinApiSystem->id,
                'portfolio_quote' => 'USDT',
                'trading_quote' => 'USDT',
                'trade_configuration_id' => 1,
                'kucoin_api_key' => config('kraite-ingestion.traders.kucoin.api_key'),
                'kucoin_api_secret' => config('kraite-ingestion.traders.kucoin.api_secret'),
                'kucoin_passphrase' => config('kraite-ingestion.traders.kucoin.passphrase'),
                'margin_percentage_long' => '5.00',
                'margin_percentage_short' => '5.00',
                'is_active' => false,
            ]);
        }
    }

    /**
     * Setup BitGet integration: Create BitGet user and account.
     */
    private function setupBitgetIntegration(ApiSystem $bitgetApiSystem): void
    {
        $bitgetEmail = config('kraite-ingestion.traders.bitget.email');

        if (! $bitgetEmail) {
            return;
        }

        $bitgetUser = User::updateOrCreate(
            ['email' => $bitgetEmail],
            [
                'name' => config('kraite-ingestion.traders.bitget.name'),
                'password' => bcrypt(config('kraite-ingestion.traders.bitget.password', 'password')),
                'is_active' => false,
                'is_admin' => false,
                'pushover_key' => config('kraite-ingestion.traders.bitget.pushover_key'),
                'notification_channels' => ['mail', 'pushover'],
            ]
        );

        $existingBitgetAccount = Account::where('user_id', $bitgetUser->id)
            ->where('api_system_id', $bitgetApiSystem->id)
            ->first();

        if (! $existingBitgetAccount) {
            Account::create([
                'uuid' => (string) Str::uuid(),
                'name' => 'Main BitGet Account',
                'user_id' => $bitgetUser->id,
                'api_system_id' => $bitgetApiSystem->id,
                'portfolio_quote' => 'USDT',
                'trading_quote' => 'USDT',
                'trade_configuration_id' => 1,
                'bitget_api_key' => config('kraite-ingestion.traders.bitget.api_key'),
                'bitget_api_secret' => config('kraite-ingestion.traders.bitget.api_secret'),
                'bitget_passphrase' => config('kraite-ingestion.traders.bitget.passphrase'),
                'margin_percentage_long' => '5.00',
                'margin_percentage_short' => '5.00',
                'is_active' => false,
            ]);
        }
    }

    /**
     * Cleanup Bybit credentials from Binance account.
     */
    private function cleanupAccountCredentials(): void
    {
        $binanceAccount = Account::find(1);

        if ($binanceAccount) {
            $binanceAccount->update([
                'bybit_api_key' => null,
                'bybit_api_secret' => null,
            ]);
        }

        $bybitAccount = Account::find(2);

        if ($bybitAccount) {
            $hasKey = ! empty($bybitAccount->bybit_api_key);
            $hasSecret = ! empty($bybitAccount->bybit_api_secret);

            if (! $hasKey || ! $hasSecret) {
                $bybitAccount->update([
                    'bybit_api_key' => config('kraite-ingestion.bybit_fallback.api_key'),
                    'bybit_api_secret' => config('kraite-ingestion.bybit_fallback.api_secret'),
                ]);
            }
        }
    }

    /**
     * Keep only the admin's Binance account active and shut every other
     * persisted account off. Belt-and-braces: individual `create`/`update`
     * calls above already flag the secondary accounts as inactive, but
     * this ensures an authoritative final state regardless of re-seed
     * ordering or columns whose defaults drift over time.
     */
    private function deactivateNonPrimaryAccounts(): void
    {
        $admin = User::where('email', config('kraite.admin_user_email'))->first();

        if (! $admin) {
            return;
        }

        $primaryBinance = Account::where('user_id', $admin->id)
            ->whereHas('apiSystem', static function ($query) {
                $query->where('canonical', 'binance');
            })
            ->value('id');

        Account::query()
            ->when($primaryBinance, static function ($query) use ($primaryBinance) {
                $query->where('id', '!=', $primaryBinance);
            })
            ->update(['is_active' => false]);

        if ($primaryBinance) {
            Account::whereKey($primaryBinance)->update(['is_active' => true]);
        }
    }
}
