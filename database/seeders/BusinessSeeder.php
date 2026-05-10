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
     *
     * On production servers (SERVER_ROLE != local/testing), only the
     * sysadmin user is created. Trader accounts are created via the
     * registration flow, not the seeder.
     */
    public function run(): void
    {
        $role = config('kraite.server_role', 'web');

        if (! in_array($role, ['local', 'testing', 'web'], strict: true) && app()->environment() !== 'local' && app()->environment() !== 'testing') {
            $this->seedSysadminOnly();

            return;
        }

        $binance = ApiSystem::where('canonical', 'binance')->firstOrFail();
        $bybit = ApiSystem::where('canonical', 'bybit')->firstOrFail();
        $kucoin = ApiSystem::where('canonical', 'kucoin')->firstOrFail();
        $bitget = ApiSystem::where('canonical', 'bitget')->firstOrFail();

        $trader = $this->seedUser();
        $this->seedKraiteTrader();
        $this->migrateAccountOwnership();
        $this->seedBinanceAccount($binance);
        $this->updatePositionProfitPrices();
        $this->migrateAccountCredentials();
        $this->setupBybitIntegration($trader, $bybit);
        $this->setupKucoinIntegration($kucoin);
        $this->setupBitgetIntegration($bitget);
        $this->setupBinanceOnlyIntegration($binance);
        $this->cleanupAccountCredentials();
        $this->deactivateNonPrimaryAccounts();
    }

    private function seedSysadminOnly(): void
    {
        User::updateOrCreate(
            ['email' => config('kraite.admin_user_email', 'bruno@kraite.com')],
            [
                'name' => config('kraite.admin_user_name', 'Admin'),
                'password' => bcrypt(config('kraite.admin_user_password', 'password')),
                'is_active' => true,
                'is_admin' => true,
            ]
        );
    }

    /**
     * Read a string config value as nullable string. Env-driven configs
     * return mixed; api keys + bcrypt-friendly secrets must be string|null
     * for the Account / User column types.
     */
    private static function stringConfig(string $key, ?string $default = null): ?string
    {
        $value = config($key, $default);

        return is_string($value) ? $value : $default;
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
            'name' => self::stringConfig('kraite-ingestion.traders.binance_bybit.name'),
            'email' => self::stringConfig('kraite-ingestion.traders.binance_bybit.email'),
            'password' => bcrypt(self::stringConfig('kraite-ingestion.traders.binance_bybit.password', 'password') ?? 'password'),
            'is_active' => false,
            'is_admin' => true,
            'pushover_key' => self::stringConfig('kraite-ingestion.traders.binance_bybit.pushover_key'),
            'notification_channels' => ['mail', 'pushover'],
        ];

        return User::updateOrCreate(
            ['email' => $userData['email']],
            $userData
        );
    }

    /**
     * Seed the Kraite primary trader. Owns the Main Binance Account; kept
     * separate from the sysadmin (admin_user_email) so administrative login
     * and live trading identity are decoupled.
     */
    private function seedKraiteTrader(): User
    {
        $userData = [
            'name' => self::stringConfig('kraite-ingestion.traders.kraite.name'),
            'email' => self::stringConfig('kraite-ingestion.traders.kraite.email'),
            'password' => bcrypt(self::stringConfig('kraite-ingestion.traders.kraite.password', 'password') ?? 'password'),
            'is_active' => true,
            'is_admin' => false,
            'pushover_key' => self::stringConfig('kraite-ingestion.traders.kraite.pushover_key'),
            'notification_channels' => ['mail', 'pushover'],
        ];

        return User::updateOrCreate(
            ['email' => $userData['email']],
            $userData
        );
    }

    /**
     * One-shot ownership migration: the Main Binance Account (Account#1)
     * historically pointed at the sysadmin (admin_user_email). Decoupling
     * sysadmin from any account means re-anchoring this row to the Kraite
     * trader before `seedBinanceAccount`'s updateOrCreate runs — otherwise
     * the (user_id, api_system_id) lookup misses and a duplicate row is
     * inserted. Idempotent: no-op once user_id already matches the trader.
     */
    private function migrateAccountOwnership(): void
    {
        $trader = User::where('email', config('kraite-ingestion.traders.kraite.email'))->first();

        if (! $trader) {
            return;
        }

        $account = Account::find(1);

        if ($account && (int) $account->user_id !== (int) $trader->id) {
            $account->update(['user_id' => $trader->id]);
        }
    }

    /**
     * Seed the primary Binance account under the Kraite trader user. This
     * is the single live persisted account — every other account created
     * later in this seeder is deactivated by `deactivateNonPrimaryAccounts()`.
     *
     * Ownership lives on the Kraite trader (bruno@kraite.com) rather than
     * the sysadmin so the admin identity stays free of any account-bound
     * trading state.
     */
    private function seedBinanceAccount(ApiSystem $binance): void
    {
        $trader = User::where('email', config('kraite-ingestion.traders.kraite.email'))->firstOrFail();

        Account::updateOrCreate(
            [
                'user_id' => $trader->id,
                'api_system_id' => $binance->id,
            ],
            [
                'uuid' => (string) Str::uuid(),
                'name' => 'Main Binance Account',
                'portfolio_quote' => 'USDT',
                'trading_quote' => 'USDT',
                'trade_configuration_id' => 1,
                'binance_api_key' => self::stringConfig('kraite-ingestion.traders.binance_bybit.binance_api_key'),
                'binance_api_secret' => self::stringConfig('kraite-ingestion.traders.binance_bybit.binance_api_secret'),
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

        if ($account === null) {
            return;
        }

        $apiKey = $account->credentials['api_key'] ?? null;
        $apiSecret = $account->credentials['api_secret'] ?? null;

        if (! is_string($apiKey) || ! is_string($apiSecret)) {
            return;
        }

        $account->binance_api_key = $apiKey;
        $account->binance_api_secret = $apiSecret;
        $account->save();
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
                'bybit_api_key' => self::stringConfig('kraite-ingestion.traders.binance_bybit.bybit_api_key'),
                'bybit_api_secret' => self::stringConfig('kraite-ingestion.traders.binance_bybit.bybit_api_secret'),
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
        $binanceEmail = self::stringConfig('kraite-ingestion.traders.binance_only.email');

        if (! $binanceEmail) {
            return;
        }

        $binanceUser = User::updateOrCreate(
            ['email' => $binanceEmail],
            [
                'name' => self::stringConfig('kraite-ingestion.traders.binance_only.name'),
                'password' => bcrypt(self::stringConfig('kraite-ingestion.traders.binance_only.password', 'password') ?? 'password'),
                'is_active' => false,
                'is_admin' => false,
                'pushover_key' => self::stringConfig('kraite-ingestion.traders.binance_only.pushover_key'),
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
                'binance_api_key' => self::stringConfig('kraite-ingestion.traders.binance_only.binance_api_key'),
                'binance_api_secret' => self::stringConfig('kraite-ingestion.traders.binance_only.binance_api_secret'),
                'margin_percentage_long' => '5.00',
                'margin_percentage_short' => '5.00',
                'is_active' => false,
                // Karine's Binance Futures account is in One-Way mode.
                // Per the dual-position-mode design (docs/02-features/
                // dual-position-mode.md) the flag matches her live
                // exchange setting; the auto-flip catch keeps it correct
                // if she ever toggles it on Binance.
                'on_hedge_mode' => false,
            ]);
        }
    }

    /**
     * Setup KuCoin integration: Create KuCoin user and account.
     */
    private function setupKucoinIntegration(ApiSystem $kucoinApiSystem): void
    {
        $kucoinEmail = self::stringConfig('kraite-ingestion.traders.kucoin.email');

        if (! $kucoinEmail) {
            return;
        }

        $kucoinUser = User::updateOrCreate(
            ['email' => $kucoinEmail],
            [
                'name' => self::stringConfig('kraite-ingestion.traders.kucoin.name'),
                'password' => bcrypt(self::stringConfig('kraite-ingestion.traders.kucoin.password', 'password') ?? 'password'),
                'is_active' => false,
                'is_admin' => false,
                'pushover_key' => self::stringConfig('kraite-ingestion.traders.kucoin.pushover_key'),
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
                'kucoin_api_key' => self::stringConfig('kraite-ingestion.traders.kucoin.api_key'),
                'kucoin_api_secret' => self::stringConfig('kraite-ingestion.traders.kucoin.api_secret'),
                'kucoin_passphrase' => self::stringConfig('kraite-ingestion.traders.kucoin.passphrase'),
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
        $bitgetEmail = self::stringConfig('kraite-ingestion.traders.bitget.email');

        if (! $bitgetEmail) {
            return;
        }

        $bitgetUser = User::updateOrCreate(
            ['email' => $bitgetEmail],
            [
                'name' => self::stringConfig('kraite-ingestion.traders.bitget.name'),
                'password' => bcrypt(self::stringConfig('kraite-ingestion.traders.bitget.password', 'password') ?? 'password'),
                'is_active' => false,
                'is_admin' => false,
                'pushover_key' => self::stringConfig('kraite-ingestion.traders.bitget.pushover_key'),
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
                'bitget_api_key' => self::stringConfig('kraite-ingestion.traders.bitget.api_key'),
                'bitget_api_secret' => self::stringConfig('kraite-ingestion.traders.bitget.api_secret'),
                'bitget_passphrase' => self::stringConfig('kraite-ingestion.traders.bitget.passphrase'),
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
                    'bybit_api_key' => self::stringConfig('kraite-ingestion.bybit_fallback.api_key'),
                    'bybit_api_secret' => self::stringConfig('kraite-ingestion.bybit_fallback.api_secret'),
                ]);
            }
        }
    }

    /**
     * Keep only the Kraite trader's Binance account active and shut every
     * other persisted account off. Belt-and-braces: individual `create`/
     * `update` calls above already flag the secondary accounts as inactive,
     * but this ensures an authoritative final state regardless of re-seed
     * ordering or columns whose defaults drift over time.
     */
    private function deactivateNonPrimaryAccounts(): void
    {
        $trader = User::where('email', config('kraite-ingestion.traders.kraite.email'))->first();

        if (! $trader) {
            return;
        }

        $primaryBinance = Account::where('user_id', $trader->id)
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
