<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The 2026-05-15 starter->basic rename left a zombie: KraiteSeeder kept
 * creating a fresh `starter` row on every seed run, priced at 0 because
 * the billing backfill had already run. That accidental free plan is now
 * promoted into a deliberate one: `black` — free forever, no caps,
 * invite-only, never listed on the public registration wizard (the
 * wizard offers `basic` + `unlimited` only). Users already linked to the
 * row (subscription_id preserved by the in-place UPDATE) simply become
 * Black members.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscriptions')
            ->where('canonical', 'starter')
            ->update([
                'canonical' => 'black',
                'name' => 'Black',
                'description' => 'Invite-only plan. Free forever, unlimited accounts, exchanges, and balance.',
                'monthly_rate_usdt' => 0,
                'trial_days' => 7,
                'max_accounts' => null,
                'max_exchanges' => null,
                'max_balance' => null,
                'is_active' => true,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('subscriptions')
            ->where('canonical', 'black')
            ->update([
                'canonical' => 'starter',
                'name' => 'Starter',
                'description' => 'Entry-level plan with 1 account, 1 exchange, and up to 10K balance.',
                'max_accounts' => 1,
                'max_exchanges' => 1,
                'max_balance' => 10000.00,
                'updated_at' => now(),
            ]);
    }
};
