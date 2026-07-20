<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Kraite\Core\Models\Kraite;
use RuntimeException;

/**
 * Sync the service-level BitGet credentials (the bot's admin key, NOT a
 * trader key) from the shared Kraite environment into the engine row,
 * where Account::admin('bitget') reads them fleet-wide.
 *
 * Run standalone after rotating the key (locally, or on athena at deploy):
 *
 *   php artisan db:seed --class=BitgetAdminCredentialsSeeder --force
 *
 * Verify afterwards with:
 *
 *   php artisan kraite:smoke-bitget-admin
 */
final class BitgetAdminCredentialsSeeder extends Seeder
{
    public function run(): void
    {
        $apiKey = (string) config('kraite.api.credentials.bitget.api_key');
        $apiSecret = (string) config('kraite.api.credentials.bitget.api_secret');
        $passphrase = (string) config('kraite.api.credentials.bitget.passphrase');

        if ($apiKey === '' || $apiSecret === '' || $passphrase === '') {
            throw new RuntimeException(
                'BITGET_API_KEY, BITGET_API_SECRET and BITGET_PASSPHRASE must all be set '.
                'in the shared Kraite environment before seeding — refusing to overwrite '.
                'working credentials with blanks.'
            );
        }

        $engine = Kraite::findOrFail(1);

        $engine->bitget_api_key = $apiKey;
        $engine->bitget_api_secret = $apiSecret;
        $engine->bitget_passphrase = $passphrase;

        $engine->save();

        $this->command?->info('BitGet admin credentials synced into the engine row.');
    }
}
